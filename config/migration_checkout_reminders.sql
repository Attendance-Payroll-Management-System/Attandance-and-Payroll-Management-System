-- ============================================================
-- Migration: Create checkout_reminders table
-- Tracks multi-step checkout reminder system for employees
-- ============================================================

CREATE TABLE IF NOT EXISTS checkout_reminders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_id INT DEFAULT NULL,
    reminder_type VARCHAR(50) NOT NULL DEFAULT 'checkout',
    reminder_level ENUM('first', 'second', 'final') NOT NULL,
    notification_status ENUM('sent', 'dismissed', 'expired') DEFAULT 'sent',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    dismissed_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_checkout_reminders_emp_date ON checkout_reminders(employee_id, sent_at);
CREATE INDEX idx_checkout_reminders_status ON checkout_reminders(notification_status);
