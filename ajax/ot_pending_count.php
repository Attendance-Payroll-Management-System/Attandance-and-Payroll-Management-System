<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

set_mmt_timezone();
$admin_id = $_SESSION['admin_id'];

$has_approver_id = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'approver_id'")->num_rows > 0;
$has_request_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'request_type'")->num_rows > 0;
$has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;

$where_parts = ["otr.status = 'Pending'"];
if ($has_approver_id) {
    $where_parts[] = "(otr.approver_id = $admin_id OR otr.approver_id IS NULL)";
}
if ($has_request_type) {
    $where_parts[] = "otr.request_type = 'employee_request'";
} elseif ($has_source) {
    $where_parts[] = "(otr.source IS NULL OR otr.source = 'employee_request')";
}
$where_filter = implode(' AND ', $where_parts);

$result = $conn->query("
    SELECT COUNT(*) as cnt
    FROM overtime_requests otr
    WHERE $where_filter
");
$cnt = $result ? (int)$result->fetch_assoc()['cnt'] : 0;

echo json_encode(['pending_count' => $cnt]);
