-- ============================================================
-- Migration: Business Logic Improvements
-- Attendance, Leave, Overtime, Payroll Integration
-- ============================================================

-- 1. Add employee status check support columns
ALTER TABLE employee
  ADD COLUMN last_status_check DATETIME DEFAULT NULL AFTER status,
  ADD INDEX idx_employee_status (status);

-- 2. Enhance attendance table
ALTER TABLE attendance
  ADD COLUMN check_in_ip VARCHAR(45) DEFAULT NULL AFTER check_out,
  ADD COLUMN check_out_ip VARCHAR(45) DEFAULT NULL AFTER check_in_ip,
  ADD COLUMN check_in_source VARCHAR(20) DEFAULT 'web' AFTER check_out_ip,
  ADD COLUMN check_out_source VARCHAR(20) DEFAULT 'web' AFTER check_in_source,
  ADD COLUMN is_manual TINYINT(1) DEFAULT 0 AFTER status,
  ADD COLUMN remarks TEXT DEFAULT NULL AFTER is_manual,
  ADD COLUMN total_working_hours DECIMAL(5,2) DEFAULT NULL AFTER remarks,
  ADD INDEX idx_attendance_employee_date (employee_id, attendance_date);

-- 3. Enhance leave_requests table  
ALTER TABLE leave_requests
  ADD COLUMN leave_duration ENUM('full_day', 'half_day') DEFAULT 'full_day' AFTER leave_type,
  ADD COLUMN half_day_period ENUM('morning', 'afternoon') DEFAULT NULL AFTER leave_duration,
  ADD COLUMN approved_by INT DEFAULT NULL AFTER status,
  ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by,
  ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER approved_at,
  ADD COLUMN is_paid TINYINT(1) DEFAULT 1 AFTER rejection_reason,
  ADD INDEX idx_leave_employee_dates (employee_id, start_date, end_date),
  ADD INDEX idx_leave_status (status),
  ADD FOREIGN KEY (approved_by) REFERENCES employee(id) ON DELETE SET NULL;

-- 4. Enhance overtime_requests table
ALTER TABLE overtime_requests
  ADD COLUMN approved_by INT DEFAULT NULL AFTER status,
  ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by,
  ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER approved_at,
  ADD COLUMN ot_type ENUM('weekday', 'weekend', 'holiday') DEFAULT 'weekday' AFTER reason,
  ADD COLUMN is_billable TINYINT(1) DEFAULT 1 AFTER ot_type,
  ADD INDEX idx_overtime_employee_date (employee_id, ot_date),
  ADD INDEX idx_overtime_status (status),
  ADD FOREIGN KEY (approved_by) REFERENCES employee(id) ON DELETE SET NULL;

-- 5. Create leave_balances table
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
);

-- 6. Create company_policy table
CREATE TABLE IF NOT EXISTS company_policies (
  id INT PRIMARY KEY AUTO_INCREMENT,
  policy_key VARCHAR(100) NOT NULL UNIQUE,
  policy_value TEXT,
  description VARCHAR(255),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO company_policies (policy_key, policy_value, description) VALUES
('work_start_time', '09:00', 'Company work start time'),
('work_end_time', '18:00', 'Company work end time'),
('late_threshold_minutes', '15', 'Minutes after start time considered late'),
('half_day_cutoff_time', '13:00', 'Check-in after this = half day'),
('grace_period_minutes', '15', 'Grace period for check-in'),
('auto_absent_hours', '4', 'Hours worked less than this = absent'),
('overtime_weekday_rate', '1.5', 'OT rate multiplier for weekdays'),
('overtime_weekend_rate', '2.0', 'OT rate multiplier for weekends'),
('overtime_holiday_rate', '3.0', 'OT rate multiplier for holidays'),
('max_overtime_hours_per_day', '4', 'Maximum OT hours allowed per day'),
('max_overtime_hours_per_month', '60', 'Maximum OT hours allowed per month'),
('leave_requires_checkout', '1', 'Require checkout before leave submission'),
('block_attendance_on_approved_leave', '1', 'Block check-in/out on approved leave dates');

-- 7. Create payroll_details table for granular payroll tracking
CREATE TABLE IF NOT EXISTS payroll_details (
  id INT PRIMARY KEY AUTO_INCREMENT,
  payroll_id INT NOT NULL,
  component_type ENUM('earning', 'deduction') NOT NULL,
  component_name VARCHAR(100) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE CASCADE
);

-- 8. Update system_settings timezone
UPDATE system_settings SET setting_value = 'Asia/Yangon' WHERE setting_key = 'timezone';
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('timezone_mmt', 'Asia/Yangon'),
('date_format_display', 'd/m/Y'),
('time_format_display', 'H:i'),
('currency_position', 'before'),
('payroll_include_attendance_deduction', '1'),
('auto_approve_leave_on_attendance', '0');
