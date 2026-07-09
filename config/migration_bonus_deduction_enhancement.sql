-- Migration: Add title, description columns and make type FK nullable
-- for bonuses and deductions tables

ALTER TABLE bonuses
  ADD COLUMN title VARCHAR(100) DEFAULT NULL AFTER employee_id,
  ADD COLUMN description TEXT DEFAULT NULL AFTER title,
  MODIFY COLUMN bonus_type_id INT NULL;

ALTER TABLE deductions
  ADD COLUMN title VARCHAR(100) DEFAULT NULL AFTER employee_id,
  ADD COLUMN description TEXT DEFAULT NULL AFTER title,
  MODIFY COLUMN deduction_type_id INT NULL;
