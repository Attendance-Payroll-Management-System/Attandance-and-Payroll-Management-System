<?php
// Temporary generator - delete after use
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
set_mmt_timezone();
$employee_id = $_SESSION["employee_id"] ?? 1;
$emp_bs = $conn->query("SELECT basic_salary FROM employee WHERE id = " . (int)$employee_id)->fetch_assoc();
echo "Ready: basic_salary=" . ($emp_bs["basic_salary"] ?? 0) . "
";
