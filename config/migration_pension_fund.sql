-- ============================================================
-- Migration: Pension Fund Deduction for AWOL Attendance
-- Deduction Rate: 2% of basic_salary per AWOL date
-- ============================================================

-- 1. Add 'Pension Fund' deduction type (replace Unauthorized Absence)
INSERT IGNORE INTO deduction_types (deduction_name) VALUES ('Pension Fund');

-- 2. Update existing deduction type name if 'Unauthorized Absence' exists
UPDATE deduction_types SET deduction_name = 'Pension Fund' WHERE deduction_name = 'Unauthorized Absence';

-- 3. Update existing deduction records to use new title
UPDATE deductions SET title = 'Pension Fund' WHERE title = 'Unauthorized Absence';

-- 4. Update existing deduction records remarks to new format
UPDATE deductions SET remarks = 'Auto Pension Fund Deduction for Unauthorized Absence' WHERE remarks = 'Automatic deduction for absent without leave';
