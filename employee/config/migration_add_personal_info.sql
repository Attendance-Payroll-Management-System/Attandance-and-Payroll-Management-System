-- Migration Script: Add employee_personal_info table
-- Run this script if you have an existing employee table and need to add personal information storage

CREATE TABLE IF NOT EXISTS employee_personal_info (
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
