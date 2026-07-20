-- ============================================================
-- Migration: Overtime Assignment Tables
-- Creates the overtime_assignments, overtime_assignment_employees,
-- and overtime_records tables required by the OT assignment module.
-- ============================================================

-- 1. overtime_assignments (header table)
CREATE TABLE IF NOT EXISTS `overtime_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assignment_code` VARCHAR(50) NOT NULL,
    `assignment_type` ENUM('department','employee') NOT NULL DEFAULT 'department',
    `department_id` INT UNSIGNED DEFAULT NULL,
    `employee_id` INT UNSIGNED DEFAULT NULL,
    `assigned_by` INT UNSIGNED NOT NULL,
    `assigned_by_name` VARCHAR(100) DEFAULT NULL,
    `assigned_by_position` VARCHAR(100) DEFAULT NULL,
    `ot_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `total_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `reason` TEXT DEFAULT NULL,
    `ot_type` VARCHAR(20) NOT NULL DEFAULT 'regular',
    `status` VARCHAR(20) NOT NULL DEFAULT 'Assigned',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_oa_code` (`assignment_code`),
    INDEX `idx_oa_date` (`ot_date`),
    INDEX `idx_oa_status` (`status`),
    INDEX `idx_oa_dept` (`department_id`),
    INDEX `idx_oa_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. overtime_assignment_employees (employee detail rows)
CREATE TABLE IF NOT EXISTS `overtime_assignment_employees` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assignment_id` INT UNSIGNED NOT NULL,
    `employee_id` INT UNSIGNED NOT NULL,
    `ot_rate` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    `ot_pay` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `eligible` TINYINT(1) NOT NULL DEFAULT 1,
    `status` VARCHAR(20) NOT NULL DEFAULT 'Assigned',
    `validation_notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_oae_assignment` (`assignment_id`),
    INDEX `idx_oae_employee` (`employee_id`),
    CONSTRAINT `fk_oae_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `overtime_assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. overtime_records (individual OT records used for payroll)
CREATE TABLE IF NOT EXISTS `overtime_records` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assignment_id` INT UNSIGNED DEFAULT NULL,
    `employee_id` INT UNSIGNED NOT NULL,
    `ot_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `total_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `ot_type` VARCHAR(20) NOT NULL DEFAULT 'regular',
    `ot_rate` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    `ot_pay` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `hourly_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status` VARCHAR(20) NOT NULL DEFAULT 'Approved',
    `payroll_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_or_assignment` (`assignment_id`),
    INDEX `idx_or_employee` (`employee_id`),
    INDEX `idx_or_date` (`ot_date`),
    INDEX `idx_or_status` (`status`),
    INDEX `idx_or_payroll` (`payroll_id`),
    CONSTRAINT `fk_or_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `overtime_assignments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
