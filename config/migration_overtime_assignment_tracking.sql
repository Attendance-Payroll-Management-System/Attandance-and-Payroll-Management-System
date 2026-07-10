ALTER TABLE overtime_requests
  ADD COLUMN assigned_by_id INT DEFAULT NULL AFTER source,
  ADD COLUMN assigned_by_name VARCHAR(100) DEFAULT NULL AFTER assigned_by_id,
  ADD COLUMN assigned_by_department VARCHAR(100) DEFAULT NULL AFTER assigned_by_name,
  ADD COLUMN assigned_by_position VARCHAR(100) DEFAULT NULL AFTER assigned_by_department,
  ADD COLUMN assigned_at DATETIME DEFAULT NULL AFTER assigned_by_position,
  ADD INDEX idx_overtime_assigned_by (assigned_by_id);
