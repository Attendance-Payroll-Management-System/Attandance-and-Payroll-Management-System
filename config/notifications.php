<?php
function create_notification($conn, $employee_id, $type, $message, $link = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (employee_id, type, message, link) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $employee_id, $type, $message, $link);
    $stmt->execute();
    $stmt->close();
}

function get_notifications($conn, $employee_id = null, $limit = 10) {
    if ($employee_id) {
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE employee_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param('ii', $employee_id, $limit);
    } else {
        $stmt = $conn->prepare("SELECT n.*, e.name as emp_name FROM notifications n LEFT JOIN employee e ON n.employee_id = e.id WHERE n.employee_id IS NULL ORDER BY n.created_at DESC LIMIT ?");
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function get_unread_count($conn, $employee_id = null) {
    if ($employee_id) {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE employee_id = ? AND is_read = 0");
        $stmt->bind_param('i', $employee_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE employee_id IS NULL AND is_read = 0");
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['cnt'];
}

function mark_notifications_read($conn, $employee_id = null) {
    if ($employee_id) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE employee_id = ?");
        $stmt->bind_param('i', $employee_id);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE employee_id IS NULL");
    }
    $stmt->execute();
    $stmt->close();
}
