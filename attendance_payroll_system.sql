-- ============================================================
-- HNIN AKARI NWE - Attendance & Payroll Management System
-- Consolidated Database Schema
-- Generated: 2026-07-14
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+06:30";

-- ============================================================
-- 1. MASTER TABLES (No Foreign Keys)
-- ============================================================

CREATE TABLE IF NOT EXISTS departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    department_name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    position_name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bonus_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bonus_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deduction_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    deduction_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS company_policies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    policy_key VARCHAR(100) NOT NULL UNIQUE,
    policy_value TEXT,
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    holiday_name VARCHAR(100) NOT NULL,
    holiday_date DATE NOT NULL,
    year YEAR NOT NULL,
    type VARCHAR(30) DEFAULT 'Public',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. EMPLOYEE TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS employee (
    id INT PRIMARY KEY AUTO_INCREMENT,
    department_id INT,
    position_id INT,
    employee_code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(30),
    gender VARCHAR(10),
    dob DATE,
    phone VARCHAR(14),
    email VARCHAR(30),
    password VARCHAR(255) NOT NULL DEFAULT '',
    hire_date DATE,
    basic_salary DECIMAL(10,2),
    status VARCHAR(20),
    last_status_check DATETIME DEFAULT NULL,
    profile_photo VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (position_id) REFERENCES positions(id),
    INDEX idx_employee_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. EMPLOYEE RELATED TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS employee_personal_info (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL UNIQUE,
    father_name VARCHAR(100),
    nrc VARCHAR(50),
    married_status VARCHAR(20),
    ethnicity VARCHAR(50),
    religion VARCHAR(50),
    permanent_address TEXT,
    allowance DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. ATTENDANCE TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in TIME,
    check_out TIME,
    status ENUM('present','absent','leave','late','half_absent','full_absent','awol','public_holiday','weekend','paid_leave','unpaid_leave','half_day') DEFAULT 'present',
    check_out_reason TEXT DEFAULT NULL,
    is_late TINYINT(1) DEFAULT 0,
    auto_calculated TINYINT(1) DEFAULT 0,
    processed_at DATETIME DEFAULT NULL,
    check_in_ip VARCHAR(45) DEFAULT NULL,
    check_out_ip VARCHAR(45) DEFAULT NULL,
    check_in_source VARCHAR(20) DEFAULT 'web',
    check_out_source VARCHAR(20) DEFAULT 'web',
    is_manual TINYINT(1) DEFAULT 0,
    remarks TEXT DEFAULT NULL,
    total_working_hours DECIMAL(5,2) DEFAULT NULL,
    UNIQUE KEY unique_employee_date (employee_id, attendance_date),
    FOREIGN KEY (employee_id) REFERENCES employee(id),
    INDEX idx_attendance_employee_date (employee_id, attendance_date),
    INDEX idx_attendance_status_date (status, attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. LEAVE TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    leave_duration ENUM('full_day','half_day') DEFAULT 'full_day',
    half_day_period ENUM('morning','afternoon') DEFAULT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'Pending',
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    is_paid TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id),
    FOREIGN KEY (approved_by) REFERENCES employee(id) ON DELETE SET NULL,
    INDEX idx_leave_employee_dates (employee_id, start_date, end_date),
    INDEX idx_leave_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_balances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    total_entitled DECIMAL(5,1) NOT NULL DEFAULT 0,
    total_taken DECIMAL(5,1) NOT NULL DEFAULT 0,
    total_pending DECIMAL(5,1) NOT NULL DEFAULT 0,
    year YEAR NOT NULL,
    UNIQUE KEY unique_employee_leave_year (employee_id, leave_type, year),
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. OVERTIME TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS overtime_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    ot_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    total_hours DECIMAL(5,2) NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'Pending',
    source VARCHAR(20) DEFAULT 'employee_request',
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    ot_type ENUM('weekday','weekend','holiday') DEFAULT 'weekday',
    is_billable TINYINT(1) DEFAULT 1,
    attendance_check TINYINT(1) DEFAULT 0,
    leave_check TINYINT(1) DEFAULT 0,
    assigned_by_id INT DEFAULT NULL,
    assigned_by_name VARCHAR(100) DEFAULT NULL,
    assigned_by_department VARCHAR(100) DEFAULT NULL,
    assigned_by_position VARCHAR(100) DEFAULT NULL,
    assigned_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id),
    FOREIGN KEY (approved_by) REFERENCES employee(id) ON DELETE SET NULL,
    INDEX idx_overtime_employee_date (employee_id, ot_date),
    INDEX idx_overtime_status (status),
    INDEX idx_overtime_assigned_by (assigned_by_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. BONUS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS bonuses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    title VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    bonus_type_id INT NULL,
    amount DECIMAL(12,2) NOT NULL,
    bonus_date DATE,
    FOREIGN KEY (employee_id) REFERENCES employee(id),
    FOREIGN KEY (bonus_type_id) REFERENCES bonus_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. DEDUCTION TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS deductions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    title VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    deduction_type_id INT NULL,
    attendance_id INT DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    deduction_rate DECIMAL(5,4) DEFAULT NULL,
    deduction_date DATE,
    remarks TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id),
    FOREIGN KEY (deduction_type_id) REFERENCES deduction_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. PAYROLL TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS payrolls (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    payroll_month TINYINT NOT NULL,
    payroll_year YEAR NOT NULL,
    basic_salary DECIMAL(12,2) NOT NULL,
    allowance_amount DECIMAL(12,2) DEFAULT 0.00,
    ot_amount DECIMAL(12,2) DEFAULT 0.00,
    bonus_amount DECIMAL(12,2) DEFAULT 0.00,
    deduction_amount DECIMAL(12,2) DEFAULT 0.00,
    tax_amount DECIMAL(12,2) DEFAULT 0.00,
    leave_deduction DECIMAL(12,2) DEFAULT 0.00,
    late_deduction DECIMAL(12,2) DEFAULT 0.00,
    unpaid_leave_deduction DECIMAL(12,2) DEFAULT 0.00,
    gross_salary DECIMAL(12,2) NOT NULL,
    net_salary DECIMAL(12,2) NOT NULL,
    working_days INT DEFAULT 0,
    present_days INT DEFAULT 0,
    half_days INT DEFAULT 0,
    late_days INT DEFAULT 0,
    absent_days INT DEFAULT 0,
    paid_leave_days INT DEFAULT 0,
    unpaid_leave_days INT DEFAULT 0,
    overtime_hours DECIMAL(6,2) DEFAULT 0.00,
    generated_date DATE,
    UNIQUE KEY unique_payroll_period (employee_id, payroll_month, payroll_year),
    FOREIGN KEY (employee_id) REFERENCES employee(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_details (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payroll_id INT NOT NULL,
    component_type ENUM('earning','deduction') NOT NULL,
    component_name VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS annual_payrolls (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    payroll_year YEAR NOT NULL,
    total_salary DECIMAL(15,2) NOT NULL,
    total_bonus DECIMAL(15,2),
    total_deduction DECIMAL(15,2),
    total_ot DECIMAL(15,2),
    net_annual_salary DECIMAL(15,2) NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employee(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 10. NOTIFICATION TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT DEFAULT NULL,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 11. ACTIVITY & LOG TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_processing_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    process_date DATE NOT NULL UNIQUE,
    processed_by VARCHAR(50) DEFAULT 'system',
    employees_processed INT DEFAULT 0,
    awol_marked INT DEFAULT 0,
    weekend_marked INT DEFAULT 0,
    holiday_marked INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 12. POLICIES TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS policies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'General',
    content TEXT NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES employee(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 13. VIEWS
-- ============================================================

CREATE OR REPLACE VIEW v_monthly_attendance_summary AS
SELECT
    a.employee_id,
    e.name AS employee_name,
    e.employee_code,
    e.basic_salary,
    d.department_name,
    YEAR(a.attendance_date) AS att_year,
    MONTH(a.attendance_date) AS att_month,
    COUNT(*) AS total_records,
    SUM(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) AS present_days,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_days,
    SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) AS half_days,
    SUM(CASE WHEN a.status IN ('paid_leave', 'leave') THEN 1 ELSE 0 END) AS paid_leave_days,
    SUM(CASE WHEN a.status = 'unpaid_leave' THEN 1 ELSE 0 END) AS unpaid_leave_days,
    SUM(CASE WHEN a.status IN ('awol', 'absent', 'full_absent') THEN 1 ELSE 0 END) AS absent_days,
    SUM(CASE WHEN a.status = 'weekend' THEN 1 ELSE 0 END) AS weekend_days,
    SUM(CASE WHEN a.status = 'public_holiday' THEN 1 ELSE 0 END) AS holiday_days,
    COALESCE(SUM(a.total_working_hours), 0) AS total_hours_worked
FROM attendance a
JOIN employee e ON a.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
GROUP BY a.employee_id, e.name, e.employee_code, e.basic_salary, d.department_name, YEAR(a.attendance_date), MONTH(a.attendance_date);

-- ============================================================
-- 14. SEED DATA
-- ============================================================

-- Departments
INSERT IGNORE INTO departments (department_name) VALUES
('Engineering'), ('Human Resources'), ('Finance'), ('Marketing'),
('Sales'), ('Operations'), ('Design'), ('Legal');

-- Positions
INSERT IGNORE INTO positions (position_name) VALUES
('Software Engineer'), ('HR Manager'), ('Accountant'), ('Marketing Specialist'),
('Sales Representative'), ('Operations Manager'), ('UI/UX Designer'), ('Legal Counsel');

-- Bonus Types
INSERT IGNORE INTO bonus_types (bonus_name) VALUES
('Performance Bonus'), ('Holiday Bonus'), ('Annual Bonus'),
('Referral Bonus'), ('Project Completion Bonus');

-- Deduction Types
INSERT IGNORE INTO deduction_types (deduction_name) VALUES
('Health Insurance'), ('Tax'), ('Social Security'),
('Pension Fund'), ('Loan Repayment');

-- System Settings
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('company_name', 'HNIN AKARI NWE'),
('company_address', 'Yangon, Myanmar'),
('company_phone', '+95 9 123 456 789'),
('company_email', 'info@hninakarinwe.com'),
('company_website', ''),
('company_logo', ''),
('payroll_currency', '$'),
('payroll_overtime_rate', '1.5'),
('payroll_tax_percent', '0'),
('payroll_working_days_per_month', '22'),
('payroll_working_hours_per_day', '8'),
('late_threshold_minutes', '5'),
('leave_annual_quota', '14'),
('sick_leave_quota', '7'),
('date_format', 'Y-m-d'),
('time_format', 'H:i:s'),
('timezone', 'Asia/Yangon'),
('timezone_mmt', 'Asia/Yangon'),
('date_format_display', 'd/m/Y'),
('time_format_display', 'H:i'),
('currency_position', 'before'),
('notify_on_leave_request', '1'),
('notify_on_overtime_request', '1'),
('notify_on_employee_register', '1'),
('payroll_include_attendance_deduction', '1'),
('auto_approve_leave_on_attendance', '0');

-- Company Policies
INSERT IGNORE INTO company_policies (policy_key, policy_value, description) VALUES
('work_start_time', '08:00', 'Company work start time'),
('work_end_time', '17:00', 'Company work end time'),
('late_threshold_minutes', '5', 'Minutes after start time considered late'),
('half_day_cutoff_time', '13:00', 'Check-in after this = half day'),
('grace_period_minutes', '5', 'Grace period for check-in'),
('auto_absent_hours', '4', 'Hours worked less than this = absent'),
('overtime_weekday_rate', '1.5', 'OT rate multiplier for weekdays'),
('overtime_weekend_rate', '2.0', 'OT rate multiplier for weekends'),
('overtime_holiday_rate', '3.0', 'OT rate multiplier for holidays'),
('max_overtime_hours_per_day', '4', 'Maximum OT hours allowed per day'),
('max_overtime_hours_per_month', '48', 'Maximum OT hours allowed per month'),
('leave_requires_checkout', '1', 'Require checkout before leave submission'),
('block_attendance_on_approved_leave', '1', 'Block check-in/out on approved leave dates'),
('unpaid_leave_deduct_full_day', '1', 'Deduct full daily salary for unpaid leave'),
('half_day_min_hours', '4', 'Minimum hours worked for half-day status'),
('full_day_min_hours', '8', 'Minimum hours for full-day present status'),
('late_penalty_per_occurrence', '5000', 'Penalty amount per late occurrence in MMK');

COMMIT;
