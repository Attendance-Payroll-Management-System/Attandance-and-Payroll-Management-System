-- ============================================================
-- Migration: Automatic Deduction for Unauthorized Absence (AWOL)
-- Deduction Rate: 2% of basic_salary per absent date
-- ============================================================

-- 1. Add columns to deductions table for AWOL tracking
ALTER TABLE deductions
  ADD COLUMN attendance_id INT DEFAULT NULL AFTER deduction_type_id,
  ADD COLUMN deduction_rate DECIMAL(5,4) DEFAULT NULL AFTER amount,
  ADD COLUMN remarks TEXT DEFAULT NULL AFTER description;

-- 2. Add 'Unauthorized Absence' deduction type
INSERT IGNORE INTO deduction_types (deduction_name) VALUES ('Unauthorized Absence');
