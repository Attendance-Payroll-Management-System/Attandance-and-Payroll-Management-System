-- Migration: Add system settings, password resets, activity logs

CREATE TABLE IF NOT EXISTS system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('company_name', 'AURA HR'),
('company_address', '123 Business Avenue, Suite 100'),
('company_phone', '+1 (555) 000-0000'),
('company_email', 'info@aurahr.com'),
('company_website', 'https://aurahr.com'),
('company_logo', ''),
('payroll_currency', '$'),
('payroll_overtime_rate', '1.5'),
('payroll_tax_percent', '0'),
('payroll_working_days_per_month', '22'),
('payroll_working_hours_per_day', '8'),
('late_threshold_minutes', '15'),
('leave_annual_quota', '14'),
('sick_leave_quota', '7'),
('date_format', 'Y-m-d'),
('time_format', 'H:i:s'),
('timezone', 'America/New_York'),
('notify_on_leave_request', '1'),
('notify_on_overtime_request', '1'),
('notify_on_employee_register', '1');

CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE SET NULL
);

ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS allowance_amount DECIMAL(12,2) DEFAULT 0 AFTER basic_salary;
ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(12,2) DEFAULT 0 AFTER deduction_amount;
ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS leave_deduction DECIMAL(12,2) DEFAULT 0 AFTER tax_amount;

INSERT IGNORE INTO departments (department_name) VALUES
('Engineering'), ('Human Resources'), ('Finance'), ('Marketing'),
('Sales'), ('Operations'), ('Design'), ('Legal');

INSERT IGNORE INTO positions (position_name) VALUES
('Software Engineer'), ('HR Manager'), ('Accountant'), ('Marketing Specialist'),
('Sales Representative'), ('Operations Manager'), ('UI/UX Designer'), ('Legal Counsel');

INSERT IGNORE INTO bonus_types (bonus_name) VALUES
('Performance Bonus'), ('Holiday Bonus'), ('Annual Bonus'),
('Referral Bonus'), ('Project Completion Bonus');

INSERT IGNORE INTO deduction_types (deduction_name) VALUES
('Health Insurance'), ('Tax'), ('Social Security'),
('Pension Fund'), ('Loan Repayment');
