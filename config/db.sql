-- 1. Master Tables (No Foreign Keys)
CREATE TABLE departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    department_name VARCHAR(50) NOT NULL
);

CREATE TABLE positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    position_name VARCHAR(50) NOT NULL
);

CREATE TABLE bonus_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bonus_name VARCHAR(100) NOT NULL
);

CREATE TABLE deduction_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    deduction_name VARCHAR(100) NOT NULL
);

-- 2. Employee Table
CREATE TABLE employee (
    id INT PRIMARY KEY AUTO_INCREMENT,
    department_id INT,
    position_id INT,
    employee_code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(30),
    gender VARCHAR(10),
    dob DATE,
    phone VARCHAR(14),
    email VARCHAR(30),
    password VARCHAR(255) NOT NULL DEFAULT '',
    hire_date DATE,   
    basic_salary DECIMAL(10,2),
    status VARCHAR(20),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (position_id) REFERENCES positions(id)
);

-- 2.1 Employee Personal Information
CREATE TABLE employee_personal_info (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL UNIQUE,
    father_name VARCHAR(100),
    nrc VARCHAR(50),
    married_status VARCHAR(20),
    ethnicity VARCHAR(50),
    religion VARCHAR(50),
    permanent_address TEXT,
    allowance DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE
);

-- 3. Transactional & Log Tables
CREATE TABLE attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in TIME,
    check_out TIME,
    status ENUM('present', 'absent', 'leave', 'late') DEFAULT 'present',
    FOREIGN KEY (employee_id) REFERENCES employee(id)
);

CREATE TABLE overtime (
    id INT PRIMARY KEY AUTO_INCREMENT,
    attendance_id INT,
    hours DECIMAL(5,2),
    rate_per_hour DECIMAL(10,2),
    amount DECIMAL(12,2),
    FOREIGN KEY (attendance_id) REFERENCES attendance(id)
);

CREATE TABLE leaves (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    leave_type VARCHAR(50),
    start_date DATE,
    end_date DATE,
    reason VARCHAR(255),
    status VARCHAR(50),
    FOREIGN KEY (employee_id) REFERENCES employee(id)
);

CREATE TABLE bonuses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    bonus_type_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    bonus_date DATE,
    FOREIGN KEY (employee_id) REFERENCES employee(id),
    FOREIGN KEY (bonus_type_id) REFERENCES bonus_types(id)
);

CREATE TABLE deductions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    deduction_type_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    deduction_date DATE,
    FOREIGN KEY (employee_id) REFERENCES employee(id),
    FOREIGN KEY (deduction_type_id) REFERENCES deduction_types(id)
);

-- 4. Payroll Tables
CREATE TABLE payrolls (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    payroll_month TINYINT NOT NULL,
    payroll_year YEAR NOT NULL,
    basic_salary DECIMAL(12,2) NOT NULL,
    ot_amount DECIMAL(12,2),
    bonus_amount DECIMAL(12,2),
    deduction_amount DECIMAL(12,2),
    gross_salary DECIMAL(12,2) NOT NULL,
    net_salary DECIMAL(12,2) NOT NULL,
    generated_date DATE,
    FOREIGN KEY (employee_id) REFERENCES employee(id)
);

CREATE TABLE annual_payrolls (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    payroll_year YEAR NOT NULL,
    total_salary DECIMAL(15,2) NOT NULL,
    total_bonus DECIMAL(15,2),
    total_deduction DECIMAL(15,2),
    total_ot DECIMAL(15,2),
    net_annual_salary DECIMAL(15,2) NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employee(id)
);



-- 1. Populate lookups
INSERT INTO departments (department_name) VALUES ('Engineering'), ('Human Resources');
INSERT INTO positions (position_name) VALUES ('Software Engineer'), ('HR Manager');
INSERT INTO bonus_types (bonus_name) VALUES ('Performance Bonus');
INSERT INTO deduction_types (deduction_name) VALUES ('Health Insurance');

-- 2. Populate an active employee profile
-- Password: pass123 (use PHP password_hash() to generate the hash for production)
INSERT INTO employee (employee_code, name, role, gender, phone, email, password, hire_date, department_id, position_id, basic_salary, status)
VALUES ('EMP001', 'John Doe', 'Developer', 'Male', '1234567890', 'john@company.com', 'pass123', '2026-01-15', 1, 1, 5000.00, 'active');
