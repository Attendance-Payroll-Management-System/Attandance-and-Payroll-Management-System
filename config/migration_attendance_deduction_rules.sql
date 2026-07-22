-- ============================================================
-- Migration: Add Attendance-Based Deduction Types
-- Adds deduction types for Half-Day and Unpaid Absence rules
-- ============================================================

SET @column_exists = (SELECT COUNT(*) FROM deduction_types WHERE deduction_name = 'Half-Day Deduction');
SET @sql = IF(@column_exists = 0,
    'INSERT INTO deduction_types (deduction_name) VALUES (''Half-Day Deduction'')',
    'SELECT "Deduction type Half-Day Deduction already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists2 = (SELECT COUNT(*) FROM deduction_types WHERE deduction_name = 'Unpaid Absence');
SET @sql2 = IF(@column_exists2 = 0,
    'INSERT INTO deduction_types (deduction_name) VALUES (''Unpaid Absence'')',
    'SELECT "Deduction type Unpaid Absence already exists"');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
