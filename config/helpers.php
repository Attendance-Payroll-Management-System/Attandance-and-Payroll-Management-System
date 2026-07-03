<?php
/**
 * Shared helper functions for Attendance, Leave, Overtime, Payroll
 * Implements MMT (Asia/Yangon) timezone and business validation rules.
 */

// ─── Timezone ───────────────────────────────────────────────────
function set_mmt_timezone(): void {
    date_default_timezone_set('Asia/Yangon');
}

function mmt_date(string $format = 'Y-m-d'): string {
    set_mmt_timezone();
    return date($format);
}

function mmt_datetime(string $format = 'Y-m-d H:i:s'): string {
    set_mmt_timezone();
    return date($format);
}

function mmt_time(string $format = 'H:i:s'): string {
    set_mmt_timezone();
    return date($format);
}

function format_mmt(string $dateStr, string $format = 'd/m/Y h:i A'): string {
    set_mmt_timezone();
    return date($format, strtotime($dateStr));
}

function get_mmt_timezone(): DateTimeZone {
    return new DateTimeZone('Asia/Yangon');
}

// ─── Employee Status Validation ─────────────────────────────────
function validate_employee_active(mysqli $conn, int $employee_id): ?string {
    $stmt = $conn->prepare("SELECT status FROM employee WHERE id = ?");
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return 'Employee record not found.';
    }
    if (strtolower($row['status']) !== 'active') {
        return 'Your account is inactive. Please contact your administrator.';
    }
    return null;
}

function require_active_employee(mysqli $conn, int $employee_id): void {
    $error = validate_employee_active($conn, $employee_id);
    if ($error) {
        $_SESSION['message'] = $error;
        $_SESSION['message_type'] = 'error';
        header('Location: dashboard.php');
        exit;
    }
}

// ─── Attendance Validation ──────────────────────────────────────
function has_checked_in_today(mysqli $conn, int $employee_id, string $date = null): ?array {
    $date = $date ?? mmt_date();
    $stmt = $conn->prepare(
        "SELECT id, check_in, check_out, status FROM attendance 
         WHERE employee_id = ? AND attendance_date = ?"
    );
    $stmt->bind_param('is', $employee_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function has_approved_leave_on_date(mysqli $conn, int $employee_id, string $date): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM leave_requests 
         WHERE employee_id = ? AND status = 'Approved' 
         AND start_date <= ? AND end_date >= ?"
    );
    $stmt->bind_param('iss', $employee_id, $date, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row['cnt'] ?? 0) > 0;
}

function has_pending_leave_on_date(mysqli $conn, int $employee_id, string $date): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM leave_requests 
         WHERE employee_id = ? AND status = 'Pending' 
         AND start_date <= ? AND end_date >= ?"
    );
    $stmt->bind_param('iss', $employee_id, $date, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row['cnt'] ?? 0) > 0;
}

function has_checked_out_today(mysqli $conn, int $employee_id, string $date = null): bool {
    $att = has_checked_in_today($conn, $employee_id, $date);
    return $att && $att['check_out'] !== null;
}

// ─── Leave Validation ───────────────────────────────────────────
function get_overlapping_leave(mysqli $conn, int $employee_id, string $start_date, string $end_date, int $exclude_id = 0): ?array {
    $stmt = $conn->prepare(
        "SELECT * FROM leave_requests 
         WHERE employee_id = ? AND id != ? 
         AND start_date <= ? AND end_date >= ?
         AND status IN ('Pending', 'Approved')"
    );
    $stmt->bind_param('iiss', $employee_id, $exclude_id, $end_date, $start_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

function get_leave_balance(mysqli $conn, int $employee_id, string $leave_type, int $year = null): array {
    $year = $year ?? (int)mmt_date('Y');
    $stmt = $conn->prepare(
        "SELECT total_entitled, total_taken, total_pending 
         FROM leave_balances 
         WHERE employee_id = ? AND leave_type = ? AND year = ?"
    );
    $stmt->bind_param('isi', $employee_id, $leave_type, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $balance = $result->fetch_assoc();
    $stmt->close();

    if (!$balance) {
        // Get default quota from settings
        $quota_map = [
            'Annual Leave' => 'leave_annual_quota',
            'Sick Leave' => 'sick_leave_quota',
        ];
        $setting_key = $quota_map[$leave_type] ?? null;
        $default_quota = 0;
        if ($setting_key) {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $s->bind_param('s', $setting_key);
            $s->execute();
            $r = $s->get_result()->fetch_assoc();
            $default_quota = (int)($r['setting_value'] ?? 0);
            $s->close();
        }
        return [
            'total_entitled' => $default_quota,
            'total_taken' => 0,
            'total_pending' => 0,
            'remaining' => $default_quota,
        ];
    }

    $balance['remaining'] = $balance['total_entitled'] - $balance['total_taken'] - $balance['total_pending'];
    return $balance;
}

function validate_leave_request(mysqli $conn, int $employee_id, string $leave_type, string $start_date, string $end_date, int $exclude_id = 0): array {
    $errors = [];

    // 1. Active employee check
    $status_error = validate_employee_active($conn, $employee_id);
    if ($status_error) {
        $errors[] = $status_error;
        return $errors;
    }

    // 2. Date validation
    $today = mmt_date();
    if ($start_date < $today) {
        $errors[] = 'Start date must be today or a future date.';
    }
    if ($end_date < $start_date) {
        $errors[] = 'End date must be on or after start date.';
    }

    if (!empty($errors)) return $errors;

    // 3. Overlapping leave check
    $overlap = get_overlapping_leave($conn, $employee_id, $start_date, $end_date, $exclude_id);
    if ($overlap) {
        $errors[] = 'You already have a ' . strtolower($overlap['status']) . ' leave request (' 
                  . $overlap['leave_type'] . ') from ' . $overlap['start_date'] . ' to ' . $overlap['end_date'] 
                  . ' that overlaps with these dates.';
    }

    // 4. Approved attendance on leave dates (company policy)
    $policy_check = $conn->query("SELECT policy_value FROM company_policies WHERE policy_key = 'block_attendance_on_approved_leave'")->fetch_assoc();
    if (($policy_check['policy_value'] ?? '1') === '1') {
        $period_start = new DateTime($start_date);
        $period_end = new DateTime($end_date);
        $period_end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($period_start, $interval, $period_end);

        foreach ($period as $date) {
            $d = $date->format('Y-m-d');
            $att = has_checked_in_today($conn, $employee_id, $d);
            // Only block if they already have an approved attendance record (not just check-in)
            if ($att !== null && $att['status'] === 'present') {
                // Check if this attendance has a complete check-in/out cycle
                if ($att['check_in'] !== null) {
                    $errors[] = "You already have attendance records on $d for this date. Please cancel the attendance first.";
                    break;
                }
            }
        }
    }

    return $errors;
}

// ─── Overtime Validation ────────────────────────────────────────
function validate_overtime_request(mysqli $conn, int $employee_id, string $ot_date, string $start_time, string $end_time, string $reason, int $exclude_id = 0): array {
    $errors = [];
    set_mmt_timezone();

    // 1. Active employee check
    $status_error = validate_employee_active($conn, $employee_id);
    if ($status_error) {
        $errors[] = $status_error;
        return $errors;
    }

    // 2. Date validation
    if (empty($ot_date) || empty($start_time) || empty($end_time)) {
        $errors[] = 'Please fill in all required fields.';
        return $errors;
    }

    // 3. Time validation
    if (strtotime($end_time) <= strtotime($start_time)) {
        $errors[] = 'End time must be later than start time.';
    }

    // 4. Check if date has approved leave
    if (has_approved_leave_on_date($conn, $employee_id, $ot_date)) {
        $errors[] = 'Cannot request overtime on a date with approved leave.';
    }

    // 5. Check if date has completed attendance
    $att = has_checked_in_today($conn, $employee_id, $ot_date);
    $has_completed_attendance = $att !== null && $att['check_in'] !== null && $att['check_out'] !== null;
    if (!$has_completed_attendance) {
        $errors[] = 'Cannot request overtime. No completed attendance found for this date. You must check in and check out first.';
    }

    // 6. Check for overlapping OT requests
    $stmt = $conn->prepare(
        "SELECT id FROM overtime_requests 
         WHERE employee_id = ? AND ot_date = ? AND id != ? 
         AND status IN ('Pending', 'Approved')
         AND start_time < ? AND end_time > ?"
    );
    $stmt->bind_param('isiss', $employee_id, $ot_date, $exclude_id, $end_time, $start_time);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $errors[] = 'You already have an overtime request that overlaps with this time period.';
    }
    $stmt->close();

    // 7. Max hours per day check
    $start_ts = strtotime($start_time);
    $end_ts = strtotime($end_time);
    $total_hours = round(($end_ts - $start_ts) / 3600, 2);
    if ($total_hours <= 0) {
        $errors[] = 'Overtime hours must be greater than zero.';
    }

    $max_policy = $conn->query("SELECT policy_value FROM company_policies WHERE policy_key = 'max_overtime_hours_per_day'")->fetch_assoc();
    $max_hours = (float)($max_policy['policy_value'] ?? 4);
    if ($total_hours > $max_hours) {
        $errors[] = "Overtime hours cannot exceed $max_hours hours per day.";
    }

    // 8. Monthly max check
    $month_start = date('Y-m-01', strtotime($ot_date));
    $month_end = date('Y-m-t', strtotime($ot_date));
    $m_stmt = $conn->prepare(
        "SELECT COALESCE(SUM(total_hours), 0) as total FROM overtime_requests 
         WHERE employee_id = ? AND status = 'Approved' AND ot_date BETWEEN ? AND ?"
    );
    $m_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $m_stmt->execute();
    $m_row = $m_stmt->get_result()->fetch_assoc();
    $m_stmt->close();
    $monthly_total = (float)($m_row['total'] ?? 0) + $total_hours;
    $max_monthly_policy = $conn->query("SELECT policy_value FROM company_policies WHERE policy_key = 'max_overtime_hours_per_month'")->fetch_assoc();
    $max_monthly = (float)($max_monthly_policy['policy_value'] ?? 60);
    if ($monthly_total > $max_monthly) {
        $errors[] = "Total overtime hours would exceed the monthly limit of $max_monthly hours.";
    }

    return $errors;
}

// ─── Payroll helpers ────────────────────────────────────────────
function get_working_days_in_month(int $year, int $month): int {
    $total_days = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $working_days = 0;
    for ($d = 1; $d <= $total_days; $d++) {
        $day_of_week = date('N', strtotime("$year-$month-$d"));
        if ($day_of_week <= 5) { // Monday to Friday
            $working_days++;
        }
    }
    return $working_days;
}

function get_company_policy(mysqli $conn, string $key, $default = null) {
    $stmt = $conn->prepare("SELECT policy_value FROM company_policies WHERE policy_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['policy_value'] ?? $default;
}

function get_attendance_summary_for_payroll(mysqli $conn, int $employee_id, string $month_start, string $month_end): array {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days
         FROM attendance 
         WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: ['total_days' => 0, 'present_days' => 0, 'absent_days' => 0, 'late_days' => 0, 'leave_days' => 0];
}

function calculate_overtime_amount_for_payroll(mysqli $conn, int $employee_id, string $month_start, string $month_end, float $hourly_rate): float {
    $stmt = $conn->prepare(
        "SELECT ot_date, start_time, end_time, total_hours FROM overtime_requests 
         WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'"
    );
    $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result();

    $total_ot_amount = 0;
    while ($row = $result->fetch_assoc()) {
        $day_of_week = date('N', strtotime($row['ot_date']));
        $rate_multiplier = 1.5; // default weekday
        if ($day_of_week >= 6) {
            $rate_multiplier = 2.0; // weekend
        }
        // Check if holiday
        $h_check = $conn->prepare("SELECT COUNT(*) as cnt FROM holidays WHERE holiday_date = ?");
        $h_check->bind_param('s', $row['ot_date']);
        $h_check->execute();
        if ($h_check->get_result()->fetch_assoc()['cnt'] > 0) {
            $rate_multiplier = 3.0; // holiday
        }
        $h_check->close();

        $total_ot_amount += $hourly_rate * $rate_multiplier * $row['total_hours'];
    }
    $stmt->close();
    return round($total_ot_amount, 2);
}

// ─── Session Message Helpers ────────────────────────────────────
function set_message(string $message, string $type = 'error'): void {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

function get_message(): array {
    $msg = $_SESSION['message'] ?? '';
    $type = $_SESSION['message_type'] ?? '';
    unset($_SESSION['message'], $_SESSION['message_type']);
    return ['message' => $msg, 'message_type' => $type];
}
