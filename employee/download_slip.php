<?php
session_start();
require_once "../config/db.php";
require_once "../config/dompdf_generator.php";

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$employee_id = $_SESSION['employee_id'];
$payroll_id = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;

if ($payroll_id <= 0) {
    die('Invalid request');
}

$stmt = $conn->prepare("
    SELECT p.*, e.name, e.employee_code
    FROM payroll p JOIN employee e ON p.employee_id = e.id
    WHERE p.id = ? AND p.employee_id = ?
");
$stmt->bind_param('ii', $payroll_id, $employee_id);
$stmt->execute();
$slip = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$slip) {
    die('Salary slip not found');
}

// Map fields for PDF generator compatibility
$slip['ot_amount'] = $slip['overtime_amount'] ?? 0;
$slip['bonus_amount'] = $slip['bonus'] ?? 0;
$slip['deduction_amount'] = ($slip['leave_deduction'] ?? 0) + ($slip['half_day_deduction'] ?? 0) + ($slip['late_deduction'] ?? 0) + ($slip['absent_deduction'] ?? 0) + ($slip['other_deduction'] ?? 0);

$month_name = date('F', mktime(0, 0, 0, $slip['pay_month'], 1));
$year = $slip['pay_year'];

$pdfContent = generate_salary_slip_pdf($slip, $month_name, $year);
$filename = "Salary_Slip_{$slip['employee_code']}_{$month_name}_{$year}.pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfContent));
header('Cache-Control: no-cache, must-revalidate');
echo $pdfContent;
exit;
