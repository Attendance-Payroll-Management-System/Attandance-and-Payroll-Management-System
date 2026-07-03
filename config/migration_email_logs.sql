-- Migration: Email Logs for Salary Slip Email Feature
-- Tracks all email delivery attempts (sent, failed, pending)

CREATE TABLE IF NOT EXISTS email_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payroll_id INT NOT NULL,
    employee_id INT NOT NULL,
    recipient_email VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    INDEX idx_email_logs_employee (employee_id),
    INDEX idx_email_logs_payroll (payroll_id),
    INDEX idx_email_logs_status (status),
    INDEX idx_email_logs_date (created_at)
);
