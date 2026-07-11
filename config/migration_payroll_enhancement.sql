-- ============================================================
-- Migration: Payroll Enhancement & Attendance Status Refinement
-- Adds paid_leave/unpaid_leave statuses, payroll columns,
-- and monthly attendance summary support.
-- ============================================================

-- 1. Expand attendance status ENUM with paid_leave and unpaid_leave
ALTER TABLE attendance
  MODIFY COLUMN status ENUM('present','absent','leave','late','half_absent','full_absent','awol','public_holiday','weekend','paid_leave','unpaid_leave','half_day') DEFAULT 'present';

-- 2. Add payroll columns for detailed salary breakdown
ALTER TABLE payrolls
  ADD COLUMN allowance_amount DECIMAL(12,2) DEFAULT 0.00 AFTER ot_amount,
  ADD COLUMN tax_amount DECIMAL(12,2) DEFAULT 0.00 AFTER deduction_amount,
  ADD COLUMN leave_deduction DECIMAL(12,2) DEFAULT 0.00 AFTER tax_amount,
  ADD COLUMN late_deduction DECIMAL(12,2) DEFAULT 0.00 AFTER leave_deduction,
  ADD COLUMN unpaid_leave_deduction DECIMAL(12,2) DEFAULT 0.00 AFTER late_deduction,
  ADD COLUMN working_days INT DEFAULT 0 AFTER unpaid_leave_deduction,
  ADD COLUMN present_days INT DEFAULT 0 AFTER working_days,
  ADD COLUMN half_days INT DEFAULT 0 AFTER present_days,
  ADD COLUMN late_days INT DEFAULT 0 AFTER half_days,
  ADD COLUMN absent_days INT DEFAULT 0 AFTER late_days,
  ADD COLUMN paid_leave_days INT DEFAULT 0 AFTER absent_days,
  ADD COLUMN unpaid_leave_days INT DEFAULT 0 AFTER paid_leave_days,
  ADD COLUMN overtime_hours DECIMAL(6,2) DEFAULT 0.00 AFTER unpaid_leave_days;

-- 3. Add company policy for unpaid leave deduction
INSERT IGNORE INTO company_policies (policy_key, policy_value, description) VALUES
('unpaid_leave_deduct_full_day', '1', 'Deduct full daily salary for unpaid leave'),
('half_day_min_hours', '4', 'Minimum hours worked for half-day status'),
('full_day_min_hours', '8', 'Minimum hours for full-day present status'),
('late_penalty_per_occurrence', '0', 'Penage amount per late occurrence (0 = no penalty)');

-- 4. Create monthly_attendance_summary view for quick access
CREATE OR REPLACE VIEW v_monthly_attendance_summary AS
SELECT
    a.employee_id,
    e.name AS employee_name,
    e.employee_code,
    e.basic_salary,
    d.department_name,
    YEAR(a.attendance_date) AS att_year,
    MONTH(a.attendance_date) AS att_month,
    COUNT(*) AS total_records,
    SUM(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) AS present_days,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_days,
    SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) AS half_days,
    SUM(CASE WHEN a.status IN ('paid_leave', 'leave') THEN 1 ELSE 0 END) AS paid_leave_days,
    SUM(CASE WHEN a.status = 'unpaid_leave' THEN 1 ELSE 0 END) AS unpaid_leave_days,
    SUM(CASE WHEN a.status IN ('awol', 'absent', 'full_absent') THEN 1 ELSE 0 END) AS absent_days,
    SUM(CASE WHEN a.status = 'weekend' THEN 1 ELSE 0 END) AS weekend_days,
    SUM(CASE WHEN a.status = 'public_holiday' THEN 1 ELSE 0 END) AS holiday_days,
    COALESCE(SUM(a.total_working_hours), 0) AS total_hours_worked
FROM attendance a
JOIN employee e ON a.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
GROUP BY a.employee_id, e.name, e.employee_code, e.basic_salary, d.department_name, YEAR(a.attendance_date), MONTH(a.attendance_date);
