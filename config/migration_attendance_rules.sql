-- ============================================================
-- Migration: Attendance Rules Implementation
-- Adds new status values, columns for comprehensive attendance
-- tracking, cross-module validation, and MMT timezone support.
-- ============================================================

-- 1. Expand attendance status ENUM with new values
ALTER TABLE attendance
  MODIFY COLUMN status ENUM('present','absent','leave','late','half_absent','full_absent','awol','public_holiday','weekend') DEFAULT 'present';

-- 2a. Ensure profile_photo column exists on employee table
ALTER TABLE employee
  ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER status;

-- 2b. Add columns for attendance rule support
-- Use individual ALTER TABLE statements per column (MySQL 8.4 doesn't support IF NOT EXISTS)
ALTER TABLE attendance
  ADD COLUMN check_out_reason TEXT DEFAULT NULL AFTER status;
ALTER TABLE attendance
  ADD COLUMN is_late TINYINT(1) DEFAULT 0 AFTER check_out_reason;
ALTER TABLE attendance
  ADD COLUMN auto_calculated TINYINT(1) DEFAULT 0 AFTER is_late;
ALTER TABLE attendance
  ADD COLUMN processed_at DATETIME DEFAULT NULL AFTER auto_calculated;
ALTER TABLE attendance
  ADD INDEX idx_attendance_status_date (status, attendance_date);

-- 3. Ensure overtime_requests have proper cross-module columns
ALTER TABLE overtime_requests
  ADD COLUMN attendance_check TINYINT(1) DEFAULT 0 AFTER is_billable;
ALTER TABLE overtime_requests
  ADD COLUMN leave_check TINYINT(1) DEFAULT 0 AFTER attendance_check;

-- 4. Create activity_log if not exists (for audit trail)
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

-- 5. Add MMT timezone setting
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('timezone_mmt', 'Asia/Yangon');

-- 6. Add attendance processing lock table to prevent duplicate cron runs
CREATE TABLE IF NOT EXISTS attendance_processing_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  process_date DATE NOT NULL UNIQUE,
  processed_by VARCHAR(50) DEFAULT 'system',
  employees_processed INT DEFAULT 0,
  awol_marked INT DEFAULT 0,
  weekend_marked INT DEFAULT 0,
  holiday_marked INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
