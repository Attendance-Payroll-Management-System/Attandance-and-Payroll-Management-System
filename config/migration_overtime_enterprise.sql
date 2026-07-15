-- ============================================================
-- Migration: Overtime Enterprise Enhancement
-- Adds overtime_settings, overtime_logs tables, new columns
-- to overtime_requests, and updated rate calculation support.
-- ============================================================

-- 1. Overtime Settings table (configurable rules)
CREATE TABLE IF NOT EXISTS overtime_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO overtime_settings (setting_key, setting_value, description) VALUES
('ot_working_day_rate', '0.02', 'Overtime rate multiplier for working days (× Basic per hour)'),
('ot_weekend_rate', '0.03', 'Overtime rate multiplier for weekends (× Basic per hour)'),
('ot_holiday_rate', '0.04', 'Overtime rate multiplier for holidays (× Basic per hour)'),
('ot_working_day_max_hours', '4', 'Max overtime hours per working day'),
('ot_weekend_max_hours', '8', 'Max overtime hours per weekend day'),
('ot_holiday_max_hours', '8', 'Max overtime hours per holiday'),
('ot_working_day_start', '17:00', 'Earliest overtime start time on working days'),
('ot_working_day_end', '21:00', 'Latest overtime end time on working days'),
('ot_weekend_start', '09:00', 'Earliest overtime start time on weekends'),
('ot_weekend_end', '17:00', 'Latest overtime end time on weekends'),
('ot_holiday_start', '09:00', 'Earliest overtime start time on holidays'),
('ot_holiday_end', '17:00', 'Latest overtime end time on holidays'),
('ot_monthly_max_hours', '60', 'Maximum total overtime hours per employee per month'),
('ot_require_attendance', '1', 'Require completed attendance check-in/out for OT (1=yes, 0=no)');

-- 2. Overtime Logs table (audit trail)
CREATE TABLE IF NOT EXISTS overtime_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    overtime_id INT NOT NULL,
    action VARCHAR(50) NOT NULL COMMENT 'created, approved, rejected, modified',
    action_by INT DEFAULT NULL COMMENT 'admin_id or employee_id',
    action_by_type VARCHAR(20) NOT NULL DEFAULT 'admin' COMMENT 'admin or employee',
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (overtime_id) REFERENCES overtime_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add new columns to overtime_requests (if not exist)
ALTER TABLE overtime_requests
    ADD COLUMN IF NOT EXISTS ot_type ENUM('working_day','weekend','holiday') DEFAULT NULL AFTER total_hours,
    ADD COLUMN IF NOT EXISTS ot_rate DECIMAL(5,4) DEFAULT NULL COMMENT 'Rate multiplier (0.02/0.03/0.04)' AFTER ot_type,
    ADD COLUMN IF NOT EXISTS ot_pay DECIMAL(12,2) DEFAULT NULL COMMENT 'Calculated overtime pay amount' AFTER ot_rate,
    ADD COLUMN IF NOT EXISTS approved_by INT DEFAULT NULL COMMENT 'Admin who approved/rejected' AFTER ot_pay,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL COMMENT 'When approval action was taken' AFTER approved_by,
    ADD INDEX IF NOT EXISTS idx_ot_type (ot_type),
    ADD INDEX IF NOT EXISTS idx_ot_date_status (ot_date, status);
