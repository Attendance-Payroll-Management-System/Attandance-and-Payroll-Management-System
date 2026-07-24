-- Add is_auto_checkout column to attendance table
ALTER TABLE attendance
  ADD COLUMN is_auto_checkout TINYINT(1) DEFAULT 0 AFTER total_working_hours;
