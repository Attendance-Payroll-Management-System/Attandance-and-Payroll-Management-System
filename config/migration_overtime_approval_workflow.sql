-- ============================================================
-- Migration: Overtime Approval Workflow Enhancement
-- Adds approver assignment, remarks, and approval date tracking
-- to support Supervisor/Manager/Admin approval workflow.
-- ============================================================

-- 1. Add approver assignment columns
ALTER TABLE overtime_requests
    ADD COLUMN IF NOT EXISTS approver_id INT DEFAULT NULL COMMENT 'ID of assigned approver (admin/supervisor/manager)' AFTER assigned_by_id,
    ADD COLUMN IF NOT EXISTS approver_type ENUM('admin', 'supervisor', 'manager') DEFAULT 'admin' COMMENT 'Type of assigned approver' AFTER approver_id,
    ADD COLUMN IF NOT EXISTS remarks TEXT DEFAULT NULL COMMENT 'Approver remarks/notes' AFTER ot_pay;

-- 2. Add index for faster approver queries
ALTER TABLE overtime_requests
    ADD INDEX IF NOT EXISTS idx_approver (approver_id, approver_type);

-- 3. Populate default approver for existing requests (set to admin)
UPDATE overtime_requests SET approver_type = 'admin' WHERE approver_type IS NULL;
