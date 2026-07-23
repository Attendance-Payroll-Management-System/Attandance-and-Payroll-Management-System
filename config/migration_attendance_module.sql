-- Attendance Management Module
-- Migration for additional tables needed by the Attendance Module

-- 1. Attendance Settings
CREATE TABLE IF NOT EXISTS attendance_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO attendance_settings (setting_key, setting_value, description) VALUES
('work_start_time', '09:00:00', 'Official work start time (MMT)'),
('work_end_time', '17:00:00', 'Official work end time (MMT)'),
('check_in_start_time', '08:30:00', 'Earliest allowed check-in time (MMT)'),
('required_working_hours', '8', 'Required working hours per day'),
('half_day_min_hours', '4', 'Minimum hours for half-day'),
('full_day_min_hours', '8', 'Minimum hours for full day'),
('grace_period_minutes', '0', 'Grace period in minutes after start time'),
('auto_absent_after_hours', '4', 'Auto-mark absent if no check-in after X hours from start'),
('enable_auto_process', '1', 'Enable automatic daily attendance processing'),
('timezone', 'Asia/Yangon', 'System timezone')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 2. Attendance Correction Requests
CREATE TABLE IF NOT EXISTS attendance_corrections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_id INT,
    attendance_date DATE NOT NULL,
    current_check_in TIME,
    current_check_out TIME,
    requested_check_in TIME,
    requested_check_out TIME,
    original_check_in TIME DEFAULT NULL,
    original_check_out TIME DEFAULT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_at DATETIME,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES employee(id) ON DELETE SET NULL,
    INDEX idx_corrections_employee (employee_id),
    INDEX idx_corrections_status (status),
    INDEX idx_corrections_date (attendance_date)
);

-- Add audit columns to existing attendance_corrections table (MySQL 5.7 compatible)
-- Run these only if the columns don't exist yet
SET @dbname = DATABASE();
SET @tablename = 'attendance_corrections';
SET @columnname = 'original_check_in';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname
   AND TABLE_NAME = @tablename
   AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TIME DEFAULT NULL AFTER requested_check_out')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = 'original_check_out';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname
   AND TABLE_NAME = @tablename
   AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TIME DEFAULT NULL AFTER original_check_in')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 3. Attendance Logs (audit trail)
CREATE TABLE IF NOT EXISTS attendance_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_id INT,
    action ENUM('check_in', 'check_out', 'auto_mark', 'manual_update', 'correction', 'admin_edit') NOT NULL,
    old_value TEXT,
    new_value TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    performed_by INT COMMENT 'NULL for system, employee_id for self, admin_id for admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
    INDEX idx_logs_employee (employee_id),
    INDEX idx_logs_action (action),
    INDEX idx_logs_date (created_at)
);

-- 4. Add columns to attendance table if not exist
ALTER TABLE attendance 
    ADD COLUMN IF NOT EXISTS check_in_note TEXT AFTER check_out_source,
    ADD COLUMN IF NOT EXISTS check_out_note TEXT AFTER check_in_note,
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,8) AFTER check_out_note,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(11,8) AFTER latitude,
    ADD COLUMN IF NOT EXISTS source ENUM('web', 'mobile', 'manual', 'system') DEFAULT 'web' AFTER longitude;

-- 5. Create monthly attendance summary view (drop & recreate)
DROP VIEW IF EXISTS v_attendance_summary;
CREATE VIEW v_attendance_summary AS
SELECT 
    a.employee_id,
    e.name AS employee_name,
    e.employee_code,
    d.department_name,
    p.position_name,
    DATE_FORMAT(a.attendance_date, '%Y-%m') AS month_key,
    a.attendance_date,
    a.check_in,
    a.check_out,
    a.total_working_hours,
    a.status,
    a.is_late
FROM attendance a
JOIN employee e ON a.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN positions p ON e.position_id = p.id
WHERE e.status = 'active';

-- 6. Monthly attendance aggregated view
DROP VIEW IF EXISTS v_monthly_attendance;
CREATE VIEW v_monthly_attendance AS
SELECT 
    a.employee_id,
    e.name AS employee_name,
    e.employee_code,
    d.department_name,
    DATE_FORMAT(a.attendance_date, '%Y-%m') AS month_key,
    YEAR(a.attendance_date) AS year,
    MONTH(a.attendance_date) AS month,
    COUNT(*) AS total_days,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_days,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_days,
    SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) AS half_days,
    SUM(CASE WHEN a.status IN ('paid_leave', 'leave') THEN 1 ELSE 0 END) AS paid_leave_days,
    SUM(CASE WHEN a.status = 'unpaid_leave' THEN 1 ELSE 0 END) AS unpaid_leave_days,
    SUM(CASE WHEN a.status IN ('awol', 'absent', 'full_absent') THEN 1 ELSE 0 END) AS absent_days,
    SUM(CASE WHEN a.status = 'weekend' THEN 1 ELSE 0 END) AS weekend_days,
    SUM(CASE WHEN a.status = 'public_holiday' THEN 1 ELSE 0 END) AS holiday_days,
    SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) AS total_late_count,
    COALESCE(SUM(a.total_working_hours), 0) AS total_hours_worked,
    COALESCE(SUM(CASE WHEN a.check_in IS NOT NULL THEN 1 ELSE 0 END), 0) AS days_with_checkin
FROM attendance a
JOIN employee e ON a.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
GROUP BY a.employee_id, e.name, e.employee_code, d.department_name, DATE_FORMAT(a.attendance_date, '%Y-%m'), YEAR(a.attendance_date), MONTH(a.attendance_date);
