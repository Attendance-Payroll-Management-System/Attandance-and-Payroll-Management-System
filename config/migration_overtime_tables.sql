-- Overtime Settings table (key-value configuration)
CREATE TABLE IF NOT EXISTS `overtime_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Overtime Logs (audit trail)
CREATE TABLE IF NOT EXISTS `overtime_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `overtime_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `action_by` int DEFAULT NULL,
  `action_by_type` varchar(20) DEFAULT 'admin',
  `old_values` text,
  `new_values` text,
  `remarks` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ot_logs_overtime_id` (`overtime_id`),
  KEY `idx_ot_logs_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings
INSERT IGNORE INTO `overtime_settings` (`setting_key`, `setting_value`, `description`) VALUES
('ot_working_day_start', '17:00', 'Working day OT start time'),
('ot_working_day_end', '21:00', 'Working day OT end time'),
('ot_working_day_max_hours', '4', 'Max hours for working day OT'),
('ot_weekend_start', '09:00', 'Weekend OT start time'),
('ot_weekend_end', '17:00', 'Weekend OT end time'),
('ot_weekend_max_hours', '8', 'Max hours for weekend OT'),
('ot_holiday_start', '09:00', 'Holiday OT start time'),
('ot_holiday_end', '17:00', 'Holiday OT end time'),
('ot_holiday_max_hours', '8', 'Max hours for holiday OT'),
('ot_monthly_max_hours', '60', 'Maximum OT hours per month'),
('ot_require_attendance', '1', 'Require completed attendance before OT'),
('payroll_working_days_per_month', '22', 'Working days per month for hourly rate');
