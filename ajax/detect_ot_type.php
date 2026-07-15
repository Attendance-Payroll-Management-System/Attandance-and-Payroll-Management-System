<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

header('Content-Type: application/json');

if (!isset($_GET['date']) || empty($_GET['date'])) {
    echo json_encode(['type' => 'working_day']);
    exit;
}

$date = $_GET['date'];
$type = detect_overtime_type($conn, $date);
echo json_encode(['type' => $type]);
