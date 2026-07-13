-- ============================================================
-- ATTENDANCE AND PAYROLL MANAGEMENT SYSTEM
-- Complete Database Schema
-- Generated for HNIN AKARI NWE HRMS
-- ============================================================

-- Create Database
CREATE DATABASE IF NOT EXISTS attendance_payroll_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE attendance_payroll_system;

-- Disable foreign key checks during import
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- 1. DEPARTMENTS
-- Stores company department information
-- ============================================================
CREATE TABLE IF NOT EXISTS departments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  department_name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. POSITIONS
-- Stores job position/role information
-- ============================================================
CREATE TABLE IF NOT EXISTS positions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  position_name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. BONUS TYPES
-- Master table for bonus categories
-- ============================================================
CREATE TABLE IF NOT EXISTS bonus_types (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  bonus_name  VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. DEDUCTION TYPES
-- Master table for deduction categories
-- ============================================================
CREATE TABLE IF NOT EXISTS deduction_types (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  deduction_name  VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. EMPLOYEE
-- Core employee table with authentication and basic info
-- ============================================================
CREATE TABLE IF NOT EXISTS employee (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  department_id       INT NOT NULL,
  position_id         INT NOT NULL,
  employee_code       VARCHAR(20) NOT NULL,
  name                VARCHAR(100) NOT NULL,
  role                VARCHAR(30) DEFAULT 'Employee',
  gender              VARCHAR(10) DEFAULT NULL,
  dob                 DATE DEFAULT NULL,
  phone               VARCHAR(14) DEFAULT NULL,
  email               VARCHAR(30) DEFAULT NULL,
  password            VARCHAR(255) NOT NULL DEFAULT '',
  hire_date           DATE DEFAULT NULL,
  basic_salary        DECIMAL(10,2) DEFAULT NULL,
  status              VARCHAR(20) DEFAULT 'active',
  profile_photo       VARCHAR(255) DEFAULT NULL,
  last_status_check   DATETIME DEFAULT NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_employee_status (status),
  FOREIGN KEY (department_id) REFERENCES departments(id) ON UPDATE CASCADE,
  FOREIGN KEY (position_id)   REFERENCES positions(id)   ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. EMPLOYEE PERSONAL INFO
-- Extended personal information (1:1 with employee)
-- ============================================================
CREATE TABLE IF NOT EXISTS employee_personal_info (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  employee_id         INT NOT NULL UNIQUE,
  father_name         VARCHAR(100) DEFAULT NULL,
  nrc                 VARCHAR(50) DEFAULT NULL,
  married_status      VARCHAR(20) DEFAULT NULL,
  ethnicity           VARCHAR(50) DEFAULT NULL,
  religion            VARCHAR(50) DEFAULT NULL,
  permanent_address   TEXT DEFAULT NULL,
  allowance           DECIMAL(10,2) DEFAULT 0.00,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. ATTENDANCE
-- Daily attendance records for each employee
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  employee_id           INT NOT NULL,
  attendance_date       DATE NOT NULL,
  check_in              TIME DEFAULT NULL,
  check_out             TIME DEFAULT NULL,
  status                ENUM('present','absent','late','half_day','full_absent','awol','public_holiday','weekend','paid_leave','unpaid_leave','half_absent') DEFAULT 'present',
  check_out_reason      TEXT DEFAULT NULL,
  is_late               TINYINT(1) DEFAULT 0,
  auto_calculated       TINYINT(1) DEFAULT 0,
  processed_at          DATETIME DEFAULT NULL,
  check_in_ip           VARCHAR(45) DEFAULT NULL,
  check_out_ip          VARCHAR(45) DEFAULT NULL,
  check_in_source       VARCHAR(20) DEFAULT 'web',
  check_out_source      VARCHAR(20) DEFAULT 'web',
  is_manual             TINYINT(1) DEFAULT 0,
  remarks               TEXT DEFAULT NULL,
  total_working_hours   DECIMAL(5,2) DEFAULT NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_attendance_status_date (status, attendance_date),
  INDEX idx_attendance_employee_date (employee_id, attendance_date),
  UNIQUE KEY unique_employee_date (employee_id, attendance_date),
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. ATTENDANCE PROCESSING LOG
-- Tracks when attendance auto-processing runs
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance_processing_log (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  process_date            DATE NOT NULL UNIQUE,
  processed_by            VARCHAR(50) DEFAULT 'system',
  employees_processed     INT DEFAULT 0,
  awol_marked             INT DEFAULT 0,
  weekend_marked          INT DEFAULT 0,
  holiday_marked          INT DEFAULT 0,
  created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. LEAVE REQUESTS
-- Employee leave request submissions and approvals
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_requests (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  employee_id         INT NOT NULL,
  leave_type          VARCHAR(50) NOT NULL,
  start_date          DATE NOT NULL,
  end_date            DATE NOT NULL,
  reason              TEXT NOT NULL,
  status              VARCHAR(20) DEFAULT 'Pending',
  leave_duration      ENUM('full_day','half_day') DEFAULT 'full_day',
  half_day_period     ENUM('morning','afternoon') DEFAULT NULL,
  approved_by         INT DEFAULT NULL,
  approved_at         DATETIME DEFAULT NULL,
  rejection_reason    TEXT DEFAULT NULL,
  is_paid             TINYINT(1) DEFAULT 1,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_leave_employee_dates (employee_id, start_date, end_date),
  INDEX idx_leave_status (status),
  FOREIGN KEY (employee_id)  REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (approved_by)  REFERENCES employee(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. LEAVE BALANCES
-- Tracks annual leave entitlements per employee per year
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_balances (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT NOT NULL,
  leave_type        VARCHAR(50) NOT NULL,
  total_entitled    DECIMAL(5,1) NOT NULL DEFAULT 0,
  total_taken       DECIMAL(5,1) NOT NULL DEFAULT 0,
  total_pending     DECIMAL(5,1) NOT NULL DEFAULT 0,
  year              YEAR NOT NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_employee_leave_year (employee_id, leave_type, year),
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. OVERTIME REQUESTS
-- Employee overtime requests and admin assignments
-- ============================================================
CREATE TABLE IF NOT EXISTS overtime_requests (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  employee_id               INT NOT NULL,
  ot_date                   DATE NOT NULL,
  start_time                TIME NOT NULL,
  end_time                  TIME NOT NULL,
  total_hours               DECIMAL(5,2) NOT NULL,
  reason                    TEXT NOT NULL,
  status                    VARCHAR(20) DEFAULT 'Pending',
  source                    VARCHAR(20) DEFAULT 'employee_request',
  approved_by               INT DEFAULT NULL,
  approved_at               DATETIME DEFAULT NULL,
  rejection_reason          TEXT DEFAULT NULL,
  ot_type                   ENUM('weekday','weekend','holiday') DEFAULT 'weekday',
  is_billable               TINYINT(1) DEFAULT 1,
  attendance_check          TINYINT(1) DEFAULT 0,
  leave_check               TINYINT(1) DEFAULT 0,
  assigned_by_id            INT DEFAULT NULL,
  assigned_by_name          VARCHAR(100) DEFAULT NULL,
  assigned_by_department    VARCHAR(100) DEFAULT NULL,
  assigned_by_position      VARCHAR(100) DEFAULT NULL,
  assigned_at               DATETIME DEFAULT NULL,
  created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_overtime_employee_date (employee_id, ot_date),
  INDEX idx_overtime_status (status),
  INDEX idx_overtime_assigned_by (assigned_by_id),
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES employee(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. BONUSES
-- Employee bonus records
-- ============================================================
CREATE TABLE IF NOT EXISTS bonuses (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT NOT NULL,
  title           VARCHAR(100) DEFAULT NULL,
  bonus_type_id   INT DEFAULT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  description     TEXT DEFAULT NULL,
  bonus_date      DATE DEFAULT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id)   REFERENCES employee(id)      ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (bonus_type_id) REFERENCES bonus_types(id)    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. DEDUCTIONS
-- Employee deduction records
-- ============================================================
CREATE TABLE IF NOT EXISTS deductions (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  employee_id         INT NOT NULL,
  title               VARCHAR(100) DEFAULT NULL,
  deduction_type_id   INT DEFAULT NULL,
  amount              DECIMAL(12,2) NOT NULL,
  description         TEXT DEFAULT NULL,
  deduction_date      DATE DEFAULT NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id)       REFERENCES employee(id)          ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (deduction_type_id) REFERENCES deduction_types(id)   ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. PAYROLLS
-- Monthly payroll records for each employee
-- ============================================================
CREATE TABLE IF NOT EXISTS payrolls (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  employee_id             INT NOT NULL,
  payroll_month           TINYINT NOT NULL,
  payroll_year            YEAR NOT NULL,
  basic_salary            DECIMAL(12,2) NOT NULL,
  allowance_amount        DECIMAL(12,2) DEFAULT 0.00,
  ot_amount               DECIMAL(12,2) DEFAULT 0.00,
  bonus_amount            DECIMAL(12,2) DEFAULT 0.00,
  deduction_amount        DECIMAL(12,2) DEFAULT 0.00,
  tax_amount              DECIMAL(12,2) DEFAULT 0.00,
  leave_deduction         DECIMAL(12,2) DEFAULT 0.00,
  late_deduction          DECIMAL(12,2) DEFAULT 0.00,
  unpaid_leave_deduction  DECIMAL(12,2) DEFAULT 0.00,
  gross_salary            DECIMAL(12,2) NOT NULL,
  net_salary              DECIMAL(12,2) NOT NULL,
  working_days            INT DEFAULT 0,
  present_days            INT DEFAULT 0,
  half_days               INT DEFAULT 0,
  late_days               INT DEFAULT 0,
  absent_days             INT DEFAULT 0,
  paid_leave_days         INT DEFAULT 0,
  unpaid_leave_days       INT DEFAULT 0,
  overtime_hours          DECIMAL(6,2) DEFAULT 0.00,
  generated_date          DATE DEFAULT NULL,
  created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_payroll_period (employee_id, payroll_month, payroll_year),
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. PAYROLL DETAILS
-- Line-item breakdown of each payroll (earnings & deductions)
-- ============================================================
CREATE TABLE IF NOT EXISTS payroll_details (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  payroll_id        INT NOT NULL,
  component_type    ENUM('earning','deduction') NOT NULL,
  component_name    VARCHAR(100) NOT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. ANNUAL PAYROLLS
-- Yearly payroll summaries per employee
-- ============================================================
CREATE TABLE IF NOT EXISTS annual_payrolls (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  employee_id         INT NOT NULL,
  payroll_year        YEAR NOT NULL,
  total_salary        DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_bonus         DECIMAL(15,2) DEFAULT 0.00,
  total_deduction     DECIMAL(15,2) DEFAULT 0.00,
  total_ot            DECIMAL(15,2) DEFAULT 0.00,
  net_annual_salary   DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_annual_payroll (employee_id, payroll_year),
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. HOLIDAYS
-- Company holiday calendar
-- ============================================================
CREATE TABLE IF NOT EXISTS holidays (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  holiday_name  VARCHAR(100) NOT NULL,
  holiday_date  DATE NOT NULL UNIQUE,
  year          YEAR NOT NULL,
  type          VARCHAR(30) DEFAULT 'Public',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. NOTIFICATIONS
-- System notifications for employees and admins
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT DEFAULT NULL,
  type          VARCHAR(50) NOT NULL,
  message       TEXT NOT NULL,
  link          VARCHAR(255) DEFAULT NULL,
  is_read       TINYINT(1) DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_employee (employee_id),
  INDEX idx_notif_unread (is_read),
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. POLICIES
-- Company policy documents (viewable by employees)
-- ============================================================
CREATE TABLE IF NOT EXISTS policies (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(200) NOT NULL,
  category      VARCHAR(50) NOT NULL DEFAULT 'General',
  content       TEXT NOT NULL,
  created_by    INT DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES employee(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. COMPANY POLICIES (System Configuration)
-- Key-value store for attendance & payroll business rules
-- ============================================================
CREATE TABLE IF NOT EXISTS company_policies (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  policy_key    VARCHAR(100) NOT NULL UNIQUE,
  policy_value  TEXT DEFAULT NULL,
  description   VARCHAR(255) DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 21. SYSTEM SETTINGS
-- General system configuration (company info, payroll rules)
-- ============================================================
CREATE TABLE IF NOT EXISTS system_settings (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  setting_key     VARCHAR(100) NOT NULL UNIQUE,
  setting_value   TEXT DEFAULT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 22. PASSWORD RESETS
-- Tokens for employee password reset flow
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  token         VARCHAR(255) NOT NULL,
  expires_at    DATETIME NOT NULL,
  used          TINYINT(1) DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 23. ACTIVITY LOGS
-- Audit trail for user actions across the system
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_logs (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT DEFAULT NULL,
  action        VARCHAR(100) NOT NULL,
  description   TEXT DEFAULT NULL,
  ip_address    VARCHAR(45) DEFAULT NULL,
  user_agent    TEXT DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 24. OVERTIME (Legacy)
-- Original overtime table linked to attendance
-- ============================================================
CREATE TABLE IF NOT EXISTS overtime (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id   INT DEFAULT NULL,
  hours           DECIMAL(5,2) DEFAULT NULL,
  rate_per_hour   DECIMAL(10,2) DEFAULT NULL,
  amount          DECIMAL(12,2) DEFAULT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 25. LEAVES (Legacy)
-- Original leave table (superseded by leave_requests)
-- ============================================================
CREATE TABLE IF NOT EXISTS leaves (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  leave_type    VARCHAR(50) DEFAULT NULL,
  start_date    DATE DEFAULT NULL,
  end_date      DATE DEFAULT NULL,
  reason        VARCHAR(255) DEFAULT NULL,
  status        VARCHAR(50) DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- VIEWS
-- ============================================================

-- Monthly Attendance Summary View (used by reports & payroll)
CREATE OR REPLACE VIEW v_monthly_attendance_summary AS
SELECT
  a.employee_id,
  e.name AS employee_name,
  e.employee_code,
  e.basic_salary,
  d.department_name,
  YEAR(a.attendance_date)  AS att_year,
  MONTH(a.attendance_date) AS att_month,
  COUNT(*)                                                     AS total_records,
  SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS present_days,
  SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END)               AS late_days,
  SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END)           AS half_days,
  SUM(CASE WHEN a.status = 'paid_leave' THEN 1 ELSE 0 END)         AS paid_leave_days,
  SUM(CASE WHEN a.status = 'unpaid_leave' THEN 1 ELSE 0 END)       AS unpaid_leave_days,
  SUM(CASE WHEN a.status IN ('absent','full_absent','awol') THEN 1 ELSE 0 END) AS absent_days,
  SUM(CASE WHEN a.status = 'weekend' THEN 1 ELSE 0 END)            AS weekend_days,
  SUM(CASE WHEN a.status = 'public_holiday' THEN 1 ELSE 0 END)     AS holiday_days,
  ROUND(SUM(COALESCE(a.total_working_hours, 0)), 2)                AS total_hours_worked
FROM attendance a
JOIN employee e     ON a.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
GROUP BY a.employee_id, YEAR(a.attendance_date), MONTH(a.attendance_date);

-- ============================================================
-- RE-ENABLE FOREIGN KEY CHECKS
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================
-- ============================================================
-- SEED DATA — Departments
-- ============================================================
-- ============================================================
INSERT INTO departments (department_name) VALUES
  ('Human Resources'),
  ('Engineering'),
  ('Finance'),
  ('Marketing'),
  ('Operations'),
  ('Sales'),
  ('Customer Support'),
  ('Administration');

-- ============================================================
-- SEED DATA — Positions
-- ============================================================
INSERT INTO positions (position_name) VALUES
  ('HR Manager'),
  ('Software Engineer'),
  ('Accountant'),
  ('Marketing Specialist'),
  ('Operations Manager'),
  ('Sales Executive'),
  ('Support Agent'),
  ('Office Administrator'),
  ('Senior Developer'),
  ('Project Manager'),
  ('Junior Developer'),
  ('Intern');

-- ============================================================
-- SEED DATA — Bonus Types
-- ============================================================
INSERT INTO bonus_types (bonus_name) VALUES
  ('Performance Bonus'),
  ('Annual Bonus'),
  ('Project Completion Bonus'),
  ('Attendance Bonus'),
  ('Referral Bonus'),
  ('Holiday Bonus'),
  ('Sales Commission');

-- ============================================================
-- SEED DATA — Deduction Types
-- ============================================================
INSERT INTO deduction_types (deduction_name) VALUES
  ('Tax'),
  ('Social Security'),
  ('Health Insurance'),
  ('Loan Repayment'),
  ('Advance Deduction'),
  ('Late Penalty'),
  ('Absent Penalty');

-- ============================================================
-- SEED DATA — Employees
-- Default admin password: admin123 (bcrypt hash)
-- ============================================================
INSERT INTO employee (department_id, position_id, employee_code, name, role, gender, dob, phone, email, password, hire_date, basic_salary, status) VALUES
  (1, 1,  'EMP001', 'Aung Myo',       'Admin',     'male',   '1990-05-15', '09123456789', 'admin@aura.hr',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-01-15', 800000.00, 'active'),
  (2, 2,  'EMP002', 'Thin Thin',      'Employee',  'female', '1995-08-20', '09234567890', 'thinthin@mail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-03-01', 600000.00, 'active'),
  (2, 9,  'EMP003', 'Kyaw Zin',       'Employee',  'male',   '1992-12-10', '09345678901', 'kyawzin@mail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-02-15', 750000.00, 'active'),
  (3, 3,  'EMP004', 'May Sander',     'Employee',  'female', '1993-04-25', '09456789012', 'maysander@mail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-04-01', 550000.00, 'active'),
  (4, 4,  'EMP005', 'Zaw Min Aung',   'Employee',  'male',   '1997-07-30', '09567890123', 'zawmin@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-05-10', 500000.00, 'active'),
  (5, 5,  'EMP006', 'Su Su',          'Employee',  'female', '1991-09-18', '09678901234', 'susu@mail.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-01-20', 650000.00, 'active'),
  (6, 6,  'EMP007', 'Htet Myat',      'Employee',  'male',   '1994-11-05', '09789012345', 'htetmyat@mail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-06-01', 480000.00, 'active'),
  (7, 7,  'EMP008', 'Nandar',         'Employee',  'female', '1996-02-14', '09890123456', 'nandar@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-07-15', 400000.00, 'active'),
  (8, 8,  'EMP009', 'Soe Tun',        'Employee',  'male',   '1989-06-22', '09901234567', 'soetun@mail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-02-01', 450000.00, 'active'),
  (2, 11, 'EMP010', 'Yuki Tanaka',    'Employee',  'female', '1998-01-08', '09112345678', 'yuki@mail.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2024-01-05', 350000.00, 'active'),
  (2, 12, 'EMP011', 'Aung Aung',      'Employee',  'male',   '2000-03-12', '09223456789', 'aungung@mail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2024-06-01', 200000.00, 'active'),
  (1, 10, 'EMP012', 'Khin Mar',       'Employee',  'female', '1993-10-30', '09334567890', 'khinmar@mail.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2023-08-01', 700000.00, 'active');

-- ============================================================
-- SEED DATA — Employee Personal Info
-- ============================================================
INSERT INTO employee_personal_info (employee_id, father_name, nrc, married_status, ethnicity, religion, permanent_address, allowance) VALUES
  (1,  'U Aung Win',     '12/ABA(N)123456', 'Married', 'Bamar',  'Buddhist',  'No.12, Pyay Road, Yangon',       50000.00),
  (2,  'U Thet Paing',   '7/OAK(N)654321',  'Single',  'Bamar',  'Buddhist',  'No.34, Mandalay Road, Mandalay', 30000.00),
  (3,  'U Zaw Min Aung', '1/AMK(N)111222',  'Married', 'Bamar',  'Buddhist',  'No.56, Insein Road, Yangon',     40000.00),
  (4,  'U Soe Aung',     '14/LMN(N)333444', 'Single',  'Shan',   'Buddhist',  'No.78, Shan State, Lashio',      25000.00),
  (5,  'U Min Thein',    '11/PP(N)555666',  'Single',  'Bamar',  'Buddhist',  'No.90, Pathein Road, Yangon',    20000.00),
  (6,  'U Kyaw Swe',     '3/YGN(N)777888',  'Married', 'Karen',  'Christian', 'No.23, Bago Road, Bago',         35000.00),
  (7,  'U Myo Aung',     '9/MND(N)999000',  'Single',  'Bamar',  'Buddhist',  'No.45, Monywa Road, Monywa',     20000.00),
  (8,  'U Tin Aung',     '5/SIT(N)123123',  'Single',  'Rakhine','Buddhist',  'No.67, Sittwe Road, Sittwe',     15000.00),
  (9,  'U Hla Myint',    '2/BGO(N)456456',  'Married', 'Bamar',  'Buddhist',  'No.89, Bago City, Bago',         25000.00),
  (10, 'U Takeshi',      '1/TKY(N)789789',  'Single',  'Bamar',  'Buddhist',  'No.11, Japanese Temple Rd, Yangon', 20000.00),
  (11, 'U Kyaw Kyaw',    '12/MPY(N)321321', 'Single',  'Bamar',  'Buddhist',  'No.33, Meiktila Road, Mandalay', 10000.00),
  (12, 'U Thein Htay',   '7/MDY(N)654654',  'Married', 'Bamar',  'Buddhist',  'No.55, 37th St, Yangon',         35000.00);

-- ============================================================
-- SEED DATA — Holidays (2024-2025)
-- ============================================================
INSERT INTO holidays (holiday_name, holiday_date, year, type) VALUES
  ('Independence Day',          '2025-01-04', 2025, 'Public'),
  ('Union Day',                 '2025-02-12', 2025, 'Public'),
  ('Peasant Day',               '2025-03-02', 2025, 'Public'),
  ('Full Moon of Tabaung',      '2025-03-14', 2025, 'Public'),
  ('Thingyan Water Festival',   '2025-04-13', 2025, 'Public'),
  ('Thingyan Water Festival',   '2025-04-14', 2025, 'Public'),
  ('Thingyan Water Festival',   '2025-04-15', 2025, 'Public'),
  ('Thingyan Water Festival',   '2025-04-16', 2025, 'Public'),
  ('Myanmar New Year Day',      '2025-04-17', 2025, 'Public'),
  ('Armed Forces Day',          '2025-03-27', 2025, 'Public'),
  ('Labour Day',                '2025-05-01', 2025, 'Public'),
  ('Martyrs Day',               '2025-07-19', 2025, 'Public'),
  ('Full Moon of Waso',         '2025-07-10', 2025, 'Public'),
  ('National Day',              '2025-11-25', 2025, 'Public'),
  ('Christmas Day',             '2025-12-25', 2025, 'Public'),
  ('Independence Day',          '2024-01-04', 2024, 'Public'),
  ('Union Day',                 '2024-02-12', 2024, 'Public'),
  ('Thingyan Water Festival',   '2024-04-13', 2024, 'Public'),
  ('Thingyan Water Festival',   '2024-04-14', 2024, 'Public'),
  ('Thingyan Water Festival',   '2024-04-15', 2024, 'Public'),
  ('Thingyan Water Festival',   '2024-04-16', 2024, 'Public'),
  ('Myanmar New Year Day',      '2024-04-17', 2024, 'Public'),
  ('Labour Day',                '2024-05-01', 2024, 'Public'),
  ('Martyrs Day',               '2024-07-19', 2024, 'Public'),
  ('Christmas Day',             '2024-12-25', 2024, 'Public');

-- ============================================================
-- SEED DATA — System Settings
-- ============================================================
INSERT INTO system_settings (setting_key, setting_value) VALUES
  ('company_name',            'HNIN AKARI NWE'),
  ('company_address',         'No. 123, Business Center, Yangon, Myanmar'),
  ('company_phone',           '+95 9 123 456 789'),
  ('company_email',           'info@hninakari.com'),
  ('company_website',         'https://hninakari.com'),
  ('payroll_currency',        'MMK'),
  ('payroll_overtime_rate',   '1.5'),
  ('payroll_tax_percent',     '5'),
  ('payroll_working_days_per_month', '26'),
  ('payroll_working_hours_per_day',  '8'),
  ('late_threshold_minutes',  '5'),
  ('leave_annual_quota',      '15'),
  ('sick_leave_quota',        '10'),
  ('timezone_mmt',            'Asia/Yangon'),
  ('date_format_display',     'M d, Y'),
  ('time_format_display',     'h:i A'),
  ('currency_position',       'before');

-- ============================================================
-- SEED DATA — Company Policies (Business Rules)
-- ============================================================
INSERT INTO company_policies (policy_key, policy_value, description) VALUES
  ('work_start_time',                 '08:00',       'Official work start time'),
  ('work_end_time',                   '17:00',       'Official work end time'),
  ('late_threshold_minutes',          '5',           'Minutes after start time considered late'),
  ('half_day_cutoff_time',            '12:00',       'Cut-off time for half-day attendance'),
  ('grace_period_minutes',            '5',           'Grace period before marking as late'),
  ('auto_absent_hours',               '4',           'Hours of no check-in to auto-mark absent'),
  ('overtime_weekday_rate',           '1.5',         'Overtime multiplier for weekdays'),
  ('overtime_weekend_rate',           '2.0',         'Overtime multiplier for weekends'),
  ('overtime_holiday_rate',           '2.0',         'Overtime multiplier for holidays'),
  ('max_overtime_hours_per_day',      '4',           'Maximum OT hours per day'),
  ('max_overtime_hours_per_month',    '48',          'Maximum OT hours per month'),
  ('leave_requires_checkout',         '1',           'Whether leave requires prior checkout'),
  ('block_attendance_on_approved_leave', '1',        'Block attendance marking on approved leave days'),
  ('unpaid_leave_deduct_full_day',    '1',           'Deduct full day salary for unpaid leave'),
  ('half_day_min_hours',              '4',           'Minimum hours for half-day attendance'),
  ('full_day_min_hours',              '8',           'Minimum hours for full-day attendance'),
  ('late_penalty_per_occurrence',     '5000',        'MMK penalty per late occurrence');

-- ============================================================
-- SEED DATA — Policies (Employee-Viewable Documents)
-- ============================================================
INSERT INTO policies (title, category, content, created_by) VALUES
  ('Attendance Policy',    'Attendance',  'All employees must check in at or before 8:00 AM. Late arrivals beyond the 5-minute grace period will be recorded. 3 late arrivals in a month will result in a written warning.', 1),
  ('Leave Policy',         'Leave',       'Employees are entitled to 15 days annual leave, 10 days sick leave, and 5 days personal leave per year. Leave requests must be submitted at least 3 days in advance.', 1),
  ('Overtime Policy',      'Overtime',    'Overtime must be pre-approved by the direct supervisor. Maximum overtime is 48 hours per month. Weekday OT is paid at 1.5x rate; weekends and holidays at 2x rate.', 1),
  ('Payroll Policy',       'Salary',      'Salaries are paid on the last working day of each month via direct bank transfer. Payslips are available through the employee portal.', 1),
  ('Code of Conduct',      'Conduct',     'All employees must maintain professional behavior, wear ID badges, follow the dress code, and treat colleagues with respect.', 1),
  ('Workplace Safety',     'Safety',      'Employees must follow all safety protocols, report hazards immediately, and complete mandatory safety training.', 1);

-- ============================================================
-- SEED DATA — Leave Balances (2025)
-- ============================================================
INSERT INTO leave_balances (employee_id, leave_type, total_entitled, total_taken, total_pending, year) VALUES
  (1,  'Annual Leave',    15.0, 3.0, 0.0, 2025),
  (1,  'Sick Leave',      10.0, 1.0, 0.0, 2025),
  (2,  'Annual Leave',    15.0, 5.0, 1.0, 2025),
  (2,  'Sick Leave',      10.0, 2.0, 0.0, 2025),
  (3,  'Annual Leave',    15.0, 2.0, 0.0, 2025),
  (3,  'Sick Leave',      10.0, 0.0, 0.0, 2025),
  (4,  'Annual Leave',    15.0, 4.0, 1.0, 2025),
  (4,  'Sick Leave',      10.0, 1.0, 0.0, 2025),
  (5,  'Annual Leave',    15.0, 1.0, 0.0, 2025),
  (5,  'Sick Leave',      10.0, 0.0, 0.0, 2025),
  (6,  'Annual Leave',    15.0, 6.0, 0.0, 2025),
  (6,  'Sick Leave',      10.0, 2.0, 0.0, 2025),
  (7,  'Annual Leave',    15.0, 2.0, 1.0, 2025),
  (7,  'Sick Leave',      10.0, 1.0, 0.0, 2025),
  (8,  'Annual Leave',    15.0, 3.0, 0.0, 2025),
  (8,  'Sick Leave',      10.0, 0.0, 0.0, 2025),
  (9,  'Annual Leave',    15.0, 0.0, 0.0, 2025),
  (9,  'Sick Leave',      10.0, 0.0, 0.0, 2025),
  (10, 'Annual Leave',    15.0, 1.0, 0.0, 2025),
  (10, 'Sick Leave',      10.0, 0.0, 0.0, 2025),
  (11, 'Annual Leave',    15.0, 0.0, 0.0, 2025),
  (11, 'Sick Leave',      10.0, 0.0, 0.0, 2025),
  (12, 'Annual Leave',    15.0, 4.0, 1.0, 2025),
  (12, 'Sick Leave',      10.0, 1.0, 0.0, 2025);

-- ============================================================
-- SEED DATA — Sample Attendance (July 2025, first 11 working days)
-- ============================================================
INSERT INTO attendance (employee_id, attendance_date, check_in, check_out, status, is_late, total_working_hours) VALUES
  -- EMP001 — Aung Myo (Admin, always on time)
  (1, '2025-07-01', '07:55:00', '17:05:00', 'present', 0, 9.17),
  (1, '2025-07-02', '07:50:00', '17:10:00', 'present', 0, 9.33),
  (1, '2025-07-03', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (1, '2025-07-04', '07:45:00', '17:15:00', 'present', 0, 9.50),
  (1, '2025-07-07', '07:58:00', '17:02:00', 'present', 0, 9.07),
  (1, '2025-07-08', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (1, '2025-07-09', '07:52:00', '17:08:00', 'present', 0, 9.27),
  (1, '2025-07-10', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (1, '2025-07-11', '07:48:00', '17:12:00', 'present', 0, 9.40),

  -- EMP002 — Thin Thin (sometimes late)
  (2, '2025-07-01', '08:15:00', '17:10:00', 'late',    1, 8.92),
  (2, '2025-07-02', '08:05:00', '17:05:00', 'late',    1, 9.00),
  (2, '2025-07-03', '07:55:00', '17:00:00', 'present', 0, 9.08),
  (2, '2025-07-04', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (2, '2025-07-07', '08:20:00', '17:15:00', 'late',    1, 8.92),
  (2, '2025-07-08', '07:50:00', '17:05:00', 'present', 0, 9.25),
  (2, '2025-07-09', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (2, '2025-07-10', '08:10:00', '17:08:00', 'late',    1, 8.97),
  (2, '2025-07-11', '07:58:00', '17:02:00', 'present', 0, 9.07),

  -- EMP003 — Kyaw Zin (consistently on time)
  (3, '2025-07-01', '07:45:00', '17:15:00', 'present', 0, 9.50),
  (3, '2025-07-02', '07:50:00', '17:10:00', 'present', 0, 9.33),
  (3, '2025-07-03', '07:48:00', '17:12:00', 'present', 0, 9.40),
  (3, '2025-07-04', '07:55:00', '17:05:00', 'present', 0, 9.17),
  (3, '2025-07-07', '07:42:00', '17:18:00', 'present', 0, 9.60),
  (3, '2025-07-08', '07:50:00', '17:10:00', 'present', 0, 9.33),
  (3, '2025-07-09', '07:55:00', '17:05:00', 'present', 0, 9.17),
  (3, '2025-07-10', '07:48:00', '17:12:00', 'present', 0, 9.40),
  (3, '2025-07-11', '07:50:00', '17:10:00', 'present', 0, 9.33),

  -- EMP004 — May Sander (mixed)
  (4, '2025-07-01', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (4, '2025-07-02', '08:30:00', '17:00:00', 'late',    1, 8.50),
  (4, '2025-07-03', '08:00:00', '13:00:00', 'half_day',0, 5.00),
  (4, '2025-07-04', '08:05:00', '17:05:00', 'late',    1, 9.00),
  (4, '2025-07-07', '07:55:00', '17:00:00', 'present', 0, 9.08),
  (4, '2025-07-08', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (4, '2025-07-09', '08:15:00', '17:10:00', 'late',    1, 8.92),
  (4, '2025-07-10', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (4, '2025-07-11', '07:50:00', '17:05:00', 'present', 0, 9.25),

  -- EMP005 — Zaw Min Aung (good attendance)
  (5, '2025-07-01', '07:50:00', '17:10:00', 'present', 0, 9.33),
  (5, '2025-07-02', '07:55:00', '17:05:00', 'present', 0, 9.17),
  (5, '2025-07-03', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (5, '2025-07-04', '07:45:00', '17:15:00', 'present', 0, 9.50),
  (5, '2025-07-07', '07:50:00', '17:10:00', 'present', 0, 9.33),
  (5, '2025-07-08', '08:00:00', '17:00:00', 'present', 0, 9.00),
  (5, '2025-07-09', '07:55:00', '17:05:00', 'present', 0, 9.17),
  (5, '2025-07-10', '07:48:00', '17:12:00', 'present', 0, 9.40),
  (5, '2025-07-11', '08:00:00', '17:00:00', 'present', 0, 9.00);

-- ============================================================
-- SEED DATA — Sample Leave Requests
-- ============================================================
INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason, status, is_paid, approved_by, approved_at) VALUES
  (2, 'Annual Leave',    '2025-07-14', '2025-07-15', 'Family vacation to Ngapali Beach',  'Approved', 1, 1, '2025-07-10 09:30:00'),
  (4, 'Sick Leave',      '2025-07-08', '2025-07-08', 'Feeling unwell, high fever',         'Approved', 1, 1, '2025-07-08 08:15:00'),
  (7, 'Annual Leave',    '2025-07-18', '2025-07-19', 'Personal matter to attend to',       'Pending',  1, NULL, NULL),
  (6, 'Annual Leave',    '2025-07-21', '2025-07-23', 'Wedding ceremony of relative',       'Pending',  1, NULL, NULL),
  (12, 'Sick Leave',     '2025-07-07', '2025-07-07', 'Doctor appointment',                 'Approved', 1, 1, '2025-07-07 07:30:00'),
  (2, 'Annual Leave',    '2025-06-10', '2025-06-11', 'Personal appointment',               'Approved', 1, 1, '2025-06-08 10:00:00'),
  (4, 'Annual Leave',    '2025-06-15', '2025-06-17', 'Family event in Mandalay',           'Approved', 1, 1, '2025-06-12 14:00:00'),
  (12, 'Annual Leave',   '2025-06-20', '2025-06-21', 'Religious festival',                 'Approved', 1, 1, '2025-06-18 09:00:00');

-- ============================================================
-- SEED DATA — Sample Overtime Requests
-- ============================================================
INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason, status, source, approved_by, approved_at, ot_type) VALUES
  (3,  '2025-07-03', '17:00:00', '20:00:00', 3.00, 'Server deployment and testing',        'Approved', 'employee_request', 1, '2025-07-03 17:10:00', 'weekday'),
  (2,  '2025-07-04', '17:00:00', '19:30:00', 2.50, 'Urgent bug fix for client report',     'Approved', 'employee_request', 1, '2025-07-04 17:05:00', 'weekday'),
  (5,  '2025-07-07', '17:00:00', '21:00:00', 4.00, 'Marketing campaign deadline',          'Approved', 'employee_request', 1, '2025-07-07 16:50:00', 'weekday'),
  (3,  '2025-07-10', '17:00:00', '19:00:00', 2.00, 'Code review and documentation',        'Pending',  'employee_request', NULL, NULL, 'weekday'),
  (7,  '2025-07-11', '17:00:00', '20:30:00', 3.50, 'Sales report compilation',             'Pending',  'employee_request', NULL, NULL, 'weekday'),
  (10, '2025-07-09', '17:00:00', '20:00:00', 3.00, 'Feature development sprint',           'Approved', 'employee_request', 1, '2025-07-09 16:55:00', 'weekday'),
  (3,  '2025-07-05', '09:00:00', '14:00:00', 5.00, 'Weekend emergency hotfix',             'Approved', 'employee_request', 1, '2025-07-05 08:30:00', 'weekend');

-- ============================================================
-- SEED DATA — Sample Bonuses
-- ============================================================
INSERT INTO bonuses (employee_id, title, bonus_type_id, amount, description, bonus_date) VALUES
  (1,  'Q1 Performance Bonus',  1, 100000.00, 'Excellent Q1 performance as Admin',          '2025-04-05'),
  (2,  'Attendance Bonus',      4, 30000.00,  'Perfect attendance in March 2025',            '2025-04-05'),
  (3,  'Project Completion',    3, 150000.00, 'Successfully completed ERP module',           '2025-05-15'),
  (5,  'Sales Commission',      7, 80000.00,  'Exceeded marketing targets Q1',               '2025-04-05'),
  (6,  'Annual Bonus',          2, 120000.00, 'Annual performance bonus 2024',               '2025-01-31'),
  (12, 'Project Completion',    3, 200000.00, 'Led successful system rollout',               '2025-06-01');

-- ============================================================
-- SEED DATA — Sample Deductions
-- ============================================================
INSERT INTO deductions (employee_id, title, deduction_type_id, amount, description, deduction_date) VALUES
  (2,  'Late Penalty - June',    6, 15000.00,  '3 late arrivals in June 2025',                '2025-07-01'),
  (4,  'Half Day Deduction',    1, 8000.00,   'Half day absence deduction',                  '2025-07-01'),
  (7,  'Social Security',       2, 2400.00,   'Monthly social security contribution',        '2025-07-01'),
  (8,  'Social Security',       2, 2000.00,   'Monthly social security contribution',        '2025-07-01'),
  (9,  'Health Insurance',      3, 5000.00,   'Monthly health insurance premium',            '2025-07-01'),
  (10, 'Tax Deduction',         1, 10500.00,  'Monthly income tax',                          '2025-07-01'),
  (11, 'Advance Deduction',     5, 50000.00,  'Salary advance repayment - installment 1/3',  '2025-07-01');

-- ============================================================
-- SEED DATA — Sample Payrolls (June & July 2025)
-- ============================================================
INSERT INTO payrolls (employee_id, payroll_month, payroll_year, basic_salary, allowance_amount, ot_amount, bonus_amount, deduction_amount, tax_amount, leave_deduction, late_deduction, gross_salary, net_salary, working_days, present_days, half_days, late_days, absent_days, overtime_hours, generated_date) VALUES
  -- June 2025
  (1,  6, 2025, 800000.00, 50000.00,  0.00,      100000.00,  0.00,       42500.00,  0.00,      0.00,       950000.00, 907500.00, 26, 26, 0, 0, 0,  0.00, '2025-06-30'),
  (2,  6, 2025, 600000.00, 30000.00,  18750.00,  30000.00,   15000.00,   31687.50,  0.00,      15000.00,   678750.00, 617062.50, 26, 23, 0, 3, 0,  2.50, '2025-06-30'),
  (3,  6, 2025, 750000.00, 40000.00,  30000.00,  150000.00,  0.00,       47000.00,  0.00,      0.00,       970000.00, 923000.00, 26, 26, 0, 0, 0,  8.00, '2025-06-30'),
  (4,  6, 2025, 550000.00, 25000.00,  0.00,       0.00,        8000.00,    28375.00,  0.00,      0.00,       575000.00, 538625.00, 26, 23, 1, 1, 1,  0.00, '2025-06-30'),
  (5,  6, 2025, 500000.00, 20000.00,  60000.00,   80000.00,   0.00,       33000.00,  0.00,      0.00,       660000.00, 627000.00, 26, 25, 0, 0, 1,  4.00, '2025-06-30'),
  (6,  6, 2025, 650000.00, 35000.00,  0.00,       120000.00,  0.00,       40250.00,  0.00,      0.00,       805000.00, 764750.00, 26, 22, 0, 0, 4,  0.00, '2025-06-30'),
  (7,  6, 2025, 480000.00, 20000.00,  26250.00,   0.00,        2400.00,    25200.00,  0.00,      0.00,       526250.00, 498650.00, 26, 24, 0, 0, 2,  3.50, '2025-06-30'),
  (8,  6, 2025, 400000.00, 15000.00,  0.00,       0.00,        2000.00,    20750.00,  0.00,      0.00,       415000.00, 392250.00, 26, 25, 0, 0, 1,  0.00, '2025-06-30'),
  (9,  6, 2025, 450000.00, 25000.00,  0.00,       0.00,        5000.00,    23500.00,  0.00,      0.00,       475000.00, 446500.00, 26, 26, 0, 0, 0,  0.00, '2025-06-30'),
  (10, 6, 2025, 350000.00, 20000.00,  22500.00,   0.00,        10500.00,   18125.00,  0.00,      0.00,       392500.00, 363875.00, 26, 24, 0, 0, 2,  3.00, '2025-06-30'),

  -- July 2025 (partial)
  (1,  7, 2025, 800000.00, 50000.00,  0.00,      0.00,        0.00,       42500.00,  0.00,      0.00,       850000.00, 807500.00, 26, 9,  0, 0, 0,  0.00, '2025-07-11'),
  (2,  7, 2025, 600000.00, 30000.00,  0.00,       0.00,        0.00,       31500.00,  0.00,      15000.00,   630000.00, 583500.00, 26, 5,  0, 4, 0,  0.00, '2025-07-11'),
  (3,  7, 2025, 750000.00, 40000.00,  3000.00,    0.00,        0.00,       39500.00,  0.00,      0.00,       793000.00, 753500.00, 26, 9,  0, 0, 0,  3.00, '2025-07-11'),
  (4,  7, 2025, 550000.00, 25000.00,  0.00,       0.00,        8000.00,    28375.00,  0.00,      0.00,       575000.00, 538625.00, 26, 6,  1, 2, 0,  0.00, '2025-07-11'),
  (5,  7, 2025, 500000.00, 20000.00,  60000.00,   0.00,        0.00,       29000.00,  0.00,      0.00,       580000.00, 551000.00, 26, 9,  0, 0, 0,  4.00, '2025-07-11');

-- ============================================================
-- SEED DATA — Annual Payroll (2024)
-- ============================================================
INSERT INTO annual_payrolls (employee_id, payroll_year, total_salary, total_bonus, total_deduction, total_ot, net_annual_salary) VALUES
  (1,  2024, 9600000.00, 100000.00, 0.00,       0.00,      9700000.00),
  (2,  2024, 7200000.00, 30000.00,  180000.00,  225000.00, 7275000.00),
  (3,  2024, 9000000.00, 150000.00, 0.00,       360000.00, 9510000.00),
  (4,  2024, 6600000.00, 0.00,      96000.00,   0.00,      6504000.00),
  (5,  2024, 6000000.00, 80000.00,  0.00,       720000.00, 6800000.00),
  (6,  2024, 7800000.00, 120000.00, 0.00,       0.00,      7920000.00),
  (7,  2024, 5760000.00, 0.00,      28800.00,   315000.00, 6046200.00),
  (8,  2024, 4800000.00, 0.00,      24000.00,   0.00,      4776000.00),
  (9,  2024, 5400000.00, 0.00,      60000.00,   0.00,      5340000.00),
  (10, 2024, 2100000.00, 0.00,      126000.00,  270000.00, 2244000.00),
  (12, 2024, 8400000.00, 200000.00, 0.00,       0.00,      8600000.00);

-- ============================================================
-- SEED DATA — Sample Notifications
-- ============================================================
INSERT INTO notifications (employee_id, type, message, link, is_read) VALUES
  (NULL, 'system',    'Welcome to HNIN AKARI NWE Payroll System!',                                      'dashboard.php',              1),
  (2,    'leave',     'Your Annual Leave request (Jul 14-15) has been approved.',                         'leaverequest.php',           0),
  (4,    'leave',     'Your Sick Leave request (Jul 8) has been approved.',                               'leaverequest.php',           1),
  (3,    'overtime',  'Your overtime request for Jul 3 has been approved. 3.0 hours recorded.',           'overtimerequest.php',        1),
  (2,    'overtime',  'Your overtime request for Jul 4 has been approved. 2.5 hours recorded.',           'overtimerequest.php',        1),
  (5,    'overtime',  'Your overtime request for Jul 7 has been approved. 4.0 hours recorded.',           'overtimerequest.php',        0),
  (10,   'overtime',  'Your overtime request for Jul 9 has been approved. 3.0 hours recorded.',           'overtimerequest.php',        0),
  (7,    'leave',     'Your Annual Leave request (Jul 18-19) is pending approval.',                      'leaverequest.php',           0),
  (6,    'leave',     'Your Annual Leave request (Jul 21-23) is pending approval.',                      'leaverequest.php',           0),
  (1,    'system',    'July 2025 payroll has been generated for 5 employees.',                           'payroll.php',                0),
  (2,    'payroll',   'Your June 2025 salary slip is now available. Net pay: $617,062.50',               'employee/payroll.php',       0),
  (NULL, 'holiday',   'Upcoming holiday: Martyrs Day on July 19, 2025.',                                 'company_policy.php',         1);

-- ============================================================
-- SEED DATA — Activity Logs
-- ============================================================
INSERT INTO activity_logs (employee_id, action, description, ip_address) VALUES
  (1,  'login',        'Admin logged in',                   '192.168.1.10'),
  (2,  'login',        'Employee logged in',                '192.168.1.20'),
  (1,  'create',       'Created new employee: Yuki Tanaka', '192.168.1.10'),
  (1,  'approve',      'Approved leave request #1',         '192.168.1.10'),
  (1,  'approve',      'Approved overtime request #1',      '192.168.1.10'),
  (2,  'check_in',     'Checked in at 08:15',               '192.168.1.20'),
  (3,  'check_in',     'Checked in at 07:45',               '192.168.1.25'),
  (3,  'overtime_req', 'Submitted OT request for Jul 3',    '192.168.1.25'),
  (1,  'payroll_gen',  'Generated payroll for July 2025',   '192.168.1.10'),
  (1,  'settings',     'Updated company settings',          '192.168.1.10');

-- ============================================================
-- Done! Database is ready.
-- Default admin login: admin@aura.hr / admin123
-- All employee passwords: admin123
-- ============================================================
