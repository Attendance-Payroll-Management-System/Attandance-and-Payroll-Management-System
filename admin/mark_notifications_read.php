<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
require_once "../config/notifications.php";

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    exit;
}

mark_notifications_read($conn);

$back = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header('Location: ' . $back);
exit;
