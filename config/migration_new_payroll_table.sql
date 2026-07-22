-- Migration: New Payroll Table
-- Replaces the old payrolls table with a cleaner schema
-- Run this AFTER backing up existing payroll data

-- 1. Create the new payroll table
CREATE TABLE IF NOT EXISTS `payroll` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `payroll_code` VARCHAR(20) NULL,
    `pay_month` TINYINT NOT NULL,
    `pay_year` YEAR NOT NULL,
    `basic_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `attendance_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `working_days` INT NOT NULL DEFAULT 0,
    `present_days` INT NOT NULL DEFAULT 0,
    `half_days` INT NOT NULL DEFAULT 0,
    `paid_leave_days` INT NOT NULL DEFAULT 0,
    `unpaid_leave_days` INT NOT NULL DEFAULT 0,
    `leave_days` INT NOT NULL DEFAULT 0,
    `late_days` INT NOT NULL DEFAULT 0,
    `absent_days` INT NOT NULL DEFAULT 0,
    `overtime_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `ot_working_day_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `ot_weekend_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `ot_holiday_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `overtime_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `leave_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unpaid_leave_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `half_day_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `late_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `absent_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `bonus` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `allowance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `other_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `gross_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `net_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payment_status` ENUM('Pending','Paid','Cancelled') DEFAULT 'Pending',
    `paid_date` DATE NULL,
    `generated_date` DATE NULL,
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_payroll` (`employee_id`, `pay_month`, `pay_year`),
    INDEX `idx_employee` (`employee_id`),
    INDEX `idx_month_year` (`pay_month`, `pay_year`),
    INDEX `idx_payment_status` (`payment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add missing columns to existing payroll table (safe ALTER)
-- Run these one by one; ignore "Duplicate column" errors

ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `payroll_code` VARCHAR(20) NULL AFTER `employee_id`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `attendance_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `basic_salary`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `half_days` INT NOT NULL DEFAULT 0 AFTER `present_days`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `paid_leave_days` INT NOT NULL DEFAULT 0 AFTER `leave_days`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `unpaid_leave_days` INT NOT NULL DEFAULT 0 AFTER `paid_leave_days`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `unpaid_leave_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `leave_deduction`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `ot_working_day_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `overtime_hours`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `ot_weekend_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `ot_working_day_hours`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `ot_holiday_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `ot_weekend_hours`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `half_day_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `leave_deduction`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `total_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `other_deduction`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `generated_date` DATE NULL AFTER `net_salary`;

-- 3. Update leave_days to include half_days if not already
-- leave_days = paid_leave + unpaid_leave (already calculated in application logic)
