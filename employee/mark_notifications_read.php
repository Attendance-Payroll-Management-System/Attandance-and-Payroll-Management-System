<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
require_once "../config/notifications.php";

if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    exit;
}

$employee_id = $_SESSION['employee_id'];
mark_notifications_read($conn, $employee_id);

// Redirect back to referring page or attendance
$back = $_SERVER['HTTP_REFERER'] ?? 'attendance.php';
header('Location: ' . $back);
exit;
