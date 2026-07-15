<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    exit;
}

$employee_id = (int)$_SESSION['employee_id'];
$notif_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($notif_id > 0) {
    $stmt = $conn->prepare("UPDATE payroll_notifications SET is_read = 1 WHERE id = ? AND (employee_id = ? OR employee_id IS NULL)");
    $stmt->bind_param("ii", $notif_id, $employee_id);
    $stmt->execute();
    $stmt->close();
}

$back = isset($_GET['ref']) ? $_GET['ref'] : 'payroll.php';
header('Location: ' . $back);
exit;
