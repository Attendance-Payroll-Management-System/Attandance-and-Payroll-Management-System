-- Migration: Unify Payroll Module
-- Adds missing columns to the `payroll` table for the complete payroll management module
-- Safe to run multiple times (uses IF NOT EXISTS)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- 1. Add payroll_code column for unique payroll identification
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `payroll_code` VARCHAR(30) NULL DEFAULT NULL AFTER `id`;

-- 2. Add status column (Draft/Generated/Reviewed/Approved/Paid/Cancelled)
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) DEFAULT 'Generated' AFTER `payment_status`;

-- 3. Add paid_date column
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `paid_date` DATE NULL DEFAULT NULL AFTER `updated_at`;

-- 4. Add generated_by column for audit trail
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `generated_by` INT NULL DEFAULT NULL AFTER `remarks`;

-- 5. Add OT breakdown columns
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `ot_working_day_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `overtime_hours`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `ot_weekend_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `ot_working_day_hours`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `ot_holiday_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `ot_weekend_hours`;

-- 6. Add total_deduction and unpaid_leave_deduction columns
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `unpaid_leave_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `leave_deduction`;
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `total_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `other_deduction`;

-- 7. Add attendance_salary column for the new calculation formula
ALTER TABLE `payroll` ADD COLUMN IF NOT EXISTS `attendance_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `basic_salary`;

-- 8. Add indexes for common queries
ALTER TABLE `payroll` ADD INDEX IF NOT EXISTS `idx_payroll_status` (`status`);
ALTER TABLE `payroll` ADD INDEX IF NOT EXISTS `idx_payroll_code` (`payroll_code`);
