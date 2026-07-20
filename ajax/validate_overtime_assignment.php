<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$assignment_type = $input['assignment_type'] ?? '';
$department_id = !empty($input['department_id']) ? (int)$input['department_id'] : null;
$employee_id = !empty($input['employee_id']) ? (int)$input['employee_id'] : null;
$ot_date = $input['ot_date'] ?? '';
$start_time = $input['start_time'] ?? '';
$end_time = $input['end_time'] ?? '';

if (empty($ot_date) || empty($start_time) || empty($end_time)) {
    echo json_encode(['valid' => false, 'errors' => ['Please fill in all required fields.'], 'ot_type' => 'working_day', 'total_hours' => 0]);
    exit;
}

// Detect OT type
$ot_type = detect_overtime_type($conn, $ot_date);

// Calculate total hours
$start_ts = strtotime($start_time);
$end_ts = strtotime($end_time);
$total_hours = round(($end_ts - $start_ts) / 3600, 2);

if ($total_hours <= 0) {
    echo json_encode(['valid' => false, 'errors' => ['End time must be after start time.'], 'ot_type' => $ot_type, 'total_hours' => 0]);
    exit;
}

// Validate time rules
$time_rules = validate_overtime_time_rules($ot_type, $start_time, $end_time);

$result = [
    'valid' => $time_rules['valid'],
    'ot_type' => $ot_type,
    'ot_type_label' => str_replace('_', ' ', ucfirst($ot_type)),
    'total_hours' => $total_hours,
    'errors' => $time_rules['errors'],
    'employees' => [],
];

if ($assignment_type === 'department' && $department_id) {
    $dept_validation = validate_department_assignment($conn, $department_id, $ot_date, $start_time, $end_time);
    $result['employees'] = $dept_validation['eligible'];
    $result['ineligible_employees'] = $dept_validation['ineligible'];
    $result['eligible_count'] = count($dept_validation['eligible']);
    $result['ineligible_count'] = count($dept_validation['ineligible']);

    if (!empty($dept_validation['errors'])) {
        $result['valid'] = false;
        $result['errors'] = array_merge($result['errors'], $dept_validation['errors']);
    } elseif (empty($dept_validation['eligible'])) {
        $result['valid'] = false;
        $result['errors'][] = 'No eligible employees found in this department for the selected date and time.';
    }
} elseif ($assignment_type === 'employee' && $employee_id) {
    $emp_validation = validate_employee_for_overtime($conn, $employee_id, $ot_date);
    $result['errors'] = array_merge($result['errors'], $emp_validation['errors']);

    // Check monthly limit
    $monthly = check_monthly_ot_limit($conn, $employee_id, $ot_date, $total_hours);
    if (!$monthly['within_limit']) {
        $result['errors'][] = $monthly['error'];
    }

    // Check duplicate
    if (check_duplicate_assignment($conn, $employee_id, $ot_date, $start_time, $end_time)) {
        $result['errors'][] = 'Duplicate overtime assignment exists for this employee.';
    }

    $result['valid'] = empty($result['errors']);
    $result['monthly_info'] = $monthly;
}

echo json_encode($result);
