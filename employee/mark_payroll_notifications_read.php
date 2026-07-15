<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    exit;
}

$employee_id = (int)$_SESSION['employee_id'];

$stmt = $conn->prepare("UPDATE payroll_notifications SET is_read = 1 WHERE (employee_id = ? OR employee_id IS NULL) AND is_read = 0");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$stmt->close();

$back = isset($_GET['ref']) ? $_GET['ref'] : 'payroll.php';
header('Location: ' . $back);
exit;
