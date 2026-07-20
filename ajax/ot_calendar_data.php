<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['employee_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

set_mmt_timezone();
$employee_id = $_SESSION['employee_id'];
$today = mmt_date();

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)mmt_date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)mmt_date('m');

$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end = date('Y-m-t', strtotime($month_start));

// 1. Get all holidays for this month
$hol_stmt = $conn->prepare("SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$hol_stmt->bind_param('ss', $month_start, $month_end);
$hol_stmt->execute();
$holidays = [];
while ($h = $hol_stmt->get_result()->fetch_assoc()) {
    $holidays[$h['holiday_date']] = $h['holiday_name'];
}
$hol_stmt->close();

// 2. Get approved leave dates for this employee
$leave_stmt = $conn->prepare(
    "SELECT start_date, end_date, leave_type FROM leave_requests
     WHERE employee_id = ? AND status = 'Approved'
     AND start_date <= ? AND end_date >= ?"
);
$leave_stmt->bind_param('iss', $employee_id, $month_end, $month_start);
$leave_stmt->execute();
$leave_dates = [];
$leave_res = $leave_stmt->get_result();
while ($l = $leave_res->fetch_assoc()) {
    $l_start = max($l['start_date'], $month_start);
    $l_end = min($l['end_date'], $month_end);
    $d = new DateTime($l_start);
    $end_d = new DateTime($l_end);
    $end_d->modify('+1 day');
    $period = new DatePeriod($d, new DateInterval('P1D'), $end_d);
    foreach ($period as $date) {
        $leave_dates[$date->format('Y-m-d')] = $l['leave_type'];
    }
}
$leave_stmt->close();

// 3. Get attendance records for this month
$att_stmt = $conn->prepare(
    "SELECT attendance_date, status, check_in, check_out FROM attendance
     WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
);
$att_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$att_stmt->execute();
$attendance = [];
$att_res = $att_stmt->get_result();
while ($a = $att_res->fetch_assoc()) {
    $attendance[$a['attendance_date']] = [
        'status' => $a['status'],
        'check_in' => $a['check_in'],
        'check_out' => $a['check_out'],
    ];
}
$att_stmt->close();

// 4. Get existing OT requests for this month
$ot_stmt = $conn->prepare(
    "SELECT ot_date, start_time, end_time, total_hours, status, source, request_type
     FROM overtime_requests
     WHERE employee_id = ? AND ot_date BETWEEN ? AND ?"
);
$ot_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$ot_stmt->execute();
$ot_requests = [];
$ot_res = $ot_stmt->get_result();
while ($o = $ot_res->fetch_assoc()) {
    $date = $o['ot_date'];
    if (!isset($ot_requests[$date])) $ot_requests[$date] = [];
    $ot_requests[$date][] = [
        'start_time' => $o['start_time'],
        'end_time' => $o['end_time'],
        'total_hours' => $o['total_hours'],
        'status' => $o['status'],
        'source' => $o['source'] ?? '',
        'request_type' => $o['request_type'] ?? '',
    ];
}
$ot_stmt->close();

// 5. Get overtime_records (assignment module)
$has_or = $conn->query("SHOW TABLES LIKE 'overtime_records'")->num_rows > 0;
if ($has_or) {
    $or_stmt = $conn->prepare(
        "SELECT ot_date, start_time, end_time, total_hours, status
         FROM overtime_records
         WHERE employee_id = ? AND ot_date BETWEEN ? AND ?"
    );
    $or_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $or_stmt->execute();
    $or_res = $or_stmt->get_result();
    while ($o = $or_res->fetch_assoc()) {
        $date = $o['ot_date'];
        if (!isset($ot_requests[$date])) $ot_requests[$date] = [];
        $ot_requests[$date][] = [
            'start_time' => $o['start_time'],
            'end_time' => $o['end_time'],
            'total_hours' => $o['total_hours'],
            'status' => $o['status'],
            'source' => 'overtime_record',
            'request_type' => 'overtime_record',
        ];
    }
    $or_stmt->close();
}

// 6. Monthly OT remaining
$monthly = check_monthly_overtime_remaining($conn, $employee_id, $today);

// 7. Employee status
$emp_stmt = $conn->prepare("SELECT status FROM employee WHERE id = ?");
$emp_stmt->bind_param('i', $employee_id);
$emp_stmt->execute();
$emp = $emp_stmt->get_result()->fetch_assoc();
$emp_stmt->close();
$employee_status = strtolower($emp['status'] ?? 'active');

// Build date status map
$date_status = [];
$start = new DateTime($month_start);
$end = new DateTime($month_end);
$end->modify('+1 day');
$period = new DatePeriod($start, new DateInterval('P1D'), $end);

foreach ($period as $date) {
    $ds = $date->format('Y-m-d');
    $day_of_week = (int)$date->format('N');

    $info = [
        'date' => $ds,
        'day_of_week' => $day_of_week,
        'is_weekend' => $day_of_week >= 6,
        'is_holiday' => isset($holidays[$ds]),
        'holiday_name' => $holidays[$ds] ?? null,
        'is_past' => $ds < $today,
        'is_today' => $ds === $today,
        'has_leave' => isset($leave_dates[$ds]),
        'leave_type' => $leave_dates[$ds] ?? null,
        'attendance' => $attendance[$ds] ?? null,
        'ot_requests' => $ot_requests[$ds] ?? [],
        'disabled' => false,
        'disable_reason' => null,
    ];

    if ($ds < $today) {
        $info['disabled'] = true;
        $info['disable_reason'] = 'Past dates cannot be selected.';
    } elseif ($employee_status !== 'active') {
        $info['disabled'] = true;
        $info['disable_reason'] = 'Your account is not active.';
    } elseif ($info['has_leave']) {
        $info['disabled'] = true;
        $info['disable_reason'] = 'Overtime cannot be requested on leave days.';
    } elseif ($info['is_weekend'] || $info['is_holiday']) {
        $has_active_ot = false;
        foreach (($ot_requests[$ds] ?? []) as $ot) {
            if (in_array($ot['status'], ['Pending', 'Approved'])) { $has_active_ot = true; break; }
        }
        if ($has_active_ot) {
            $info['has_existing_ot'] = true;
        }
    } else {
        $att = $attendance[$ds] ?? null;
        $att_status = $att['status'] ?? null;
        $absent_statuses = ['awol', 'absent', 'full_absent', 'half_absent'];

        if (in_array($att_status, $absent_statuses)) {
            $info['disabled'] = true;
            $info['disable_reason'] = 'Employee is absent. Overtime is not allowed.';
        } elseif (!$info['is_past'] && $ds > $today) {
            $has_active_ot = false;
            foreach (($ot_requests[$ds] ?? []) as $ot) {
                if (in_array($ot['status'], ['Pending', 'Approved'])) { $has_active_ot = true; break; }
            }
            if ($has_active_ot) {
                $info['has_existing_ot'] = true;
            }
        }
    }

    $date_status[$ds] = $info;
}

echo json_encode([
    'dates' => $date_status,
    'monthly' => $monthly,
    'employee_status' => $employee_status,
]);
