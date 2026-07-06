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
            SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days,
            SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as effective_present_days,
            SUM(CASE WHEN status IN ('awol', 'absent', 'full_absent', 'half_absent') THEN 1 ELSE 0 END) as absent_days_total,
            SUM(CASE WHEN status IN ('weekend', 'public_holiday') THEN 1 ELSE 0 END) as non_working_days
         FROM attendance 
         WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: ['total_days' => 0, 'present_days' => 0, 'absent_days' => 0, 'late_days' => 0, 'leave_days' => 0, 'effective_present_days' => 0, 'absent_days_total' => 0, 'non_working_days' => 0];
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

// ─── CSRF Protection ──────────────────────────────────────────
function generate_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}

function validate_csrf_token(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ─── Day Type Checks (Weekend / Holiday / Working Day) ─────────

function is_weekend(string $date): bool {
    set_mmt_timezone();
    $day_of_week = (int)date('N', strtotime($date));
    return $day_of_week >= 6; // Saturday=6, Sunday=7
}

function is_public_holiday(mysqli $conn, string $date): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM holidays WHERE holiday_date = ?");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row['cnt'] ?? 0) > 0;
}

function is_working_day(mysqli $conn, string $date): bool {
    return !is_weekend($date) && !is_public_holiday($conn, $date);
}

// ─── Late Check-in Detection ───────────────────────────────────

function is_late_checkin(string $check_in_time): bool {
    $threshold = '09:00:00';
    return strtotime($check_in_time) > strtotime($threshold);
}

function get_late_threshold(): string {
    return '09:00:00';
}

// ─── Attendance Status Auto-Calculation ────────────────────────

function calculate_attendance_status(mysqli $conn, int $employee_id, string $date, ?string $check_in, ?string $check_out, ?float $total_hours): string {
    set_mmt_timezone();

    // Rule 1: Weekend
    if (is_weekend($date)) {
        return 'weekend';
    }

    // Rule 2: Public Holiday
    if (is_public_holiday($conn, $date)) {
        return 'public_holiday';
    }

    // Rule 3: Approved Leave
    if (has_approved_leave_on_date($conn, $employee_id, $date)) {
        return 'leave';
    }

    // No check-in at all
    if ($check_in === null) {
        return 'awol';
    }

    // Has check-in
    $is_late = is_late_checkin($check_in);

    // Has check-out
    if ($check_out !== null) {
        $check_out_time = date('H:i:s', strtotime($check_out));

        // Rule 4: Check-out before 12:00 PM → Full-Day Absent
        if (strtotime($check_out_time) < strtotime('12:00:00')) {
            return 'full_absent';
        }

        // Rule 5: Check-out at/after 12:00 PM but incomplete day → Half-Day Absent
        $standard_hours = (float)get_system_setting($conn, 'payroll_working_hours_per_day', '8');
        if ($total_hours !== null && $total_hours < $standard_hours) {
            return 'half_absent';
        }
    }

    // Rule 3: Late check-in but present
    if ($is_late) {
        return 'late';
    }

    return 'present';
}

function get_system_setting(mysqli $conn, string $key, $default = null) {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['setting_value'] ?? $default;
}

function is_attendance_blocked_date(mysqli $conn, string $date): string {
    if (is_weekend($date)) {
        return 'weekend';
    }
    if (is_public_holiday($conn, $date)) {
        return 'public_holiday';
    }
    return '';
}

function get_attendance_block_message(string $block_type): string {
    return match ($block_type) {
        'weekend' => 'Attendance cannot be recorded on Saturdays or Sundays.',
        'public_holiday' => 'Attendance cannot be recorded on public holidays.',
        default => '',
    };
}

// ─── Attendance Record Update after Check-out ──────────────────

function recalculate_attendance_after_checkout(mysqli $conn, int $attendance_id, int $employee_id, string $date, string $check_in, string $check_out, float $total_hours): string {
    $new_status = calculate_attendance_status($conn, $employee_id, $date, $check_in, $check_out, $total_hours);

    $is_late_flag = is_late_checkin($check_in) ? 1 : 0;

    $stmt = $conn->prepare(
        "UPDATE attendance SET status = ?, is_late = ?, auto_calculated = 1, total_working_hours = ? WHERE id = ?"
    );
    $stmt->bind_param('sidi', $new_status, $is_late_flag, $total_hours, $attendance_id);
    $stmt->execute();
    $stmt->close();

    return $new_status;
}

// ─── Auto-Mark AWOL, Weekend, Public Holiday ──────────────────

function auto_mark_daily_attendance(mysqli $conn, string $date): array {
    set_mmt_timezone();

    $result = [
        'processed' => 0,
        'awol' => 0,
        'weekend' => 0,
        'holiday' => 0,
        'errors' => [],
    ];

    // Get all active employees
    $emps = $conn->query("SELECT id, name FROM employee WHERE status = 'active'");
    if (!$emps) return $result;

    $is_wknd = is_weekend($date);
    $is_hol = is_public_holiday($conn, $date);

    while ($emp = $emps->fetch_assoc()) {
        $eid = (int)$emp['id'];

        // Check if attendance already exists for this date
        $check = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $check->bind_param('is', $eid, $date);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();

        if ($exists) continue;

        $result['processed']++;

        if ($is_wknd) {
            $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, status, auto_calculated) VALUES (?, ?, 'weekend', 1)");
            $stmt->bind_param('is', $eid, $date);
            $stmt->execute();
            $stmt->close();
            $result['weekend']++;
        } elseif ($is_hol) {
            $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, status, auto_calculated) VALUES (?, ?, 'public_holiday', 1)");
            $stmt->bind_param('is', $eid, $date);
            $stmt->execute();
            $stmt->close();
            $result['holiday']++;
        } elseif (!has_approved_leave_on_date($conn, $eid, $date) && !has_pending_leave_on_date($conn, $eid, $date)) {
            $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, status, auto_calculated) VALUES (?, ?, 'awol', 1)");
            $stmt->bind_param('is', $eid, $date);
            $stmt->execute();
            $stmt->close();
            $result['awol']++;
        }
    }

    return $result;
}

// ─── Duplicate Check Prevention ────────────────────────────────

function has_duplicate_attendance(mysqli $conn, int $employee_id, string $date): bool {
    $stmt = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $stmt->bind_param('is', $employee_id, $date);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function has_check_in_today(mysqli $conn, int $employee_id, string $date): bool {
    $att = has_checked_in_today($conn, $employee_id, $date);
    return $att !== null && $att['check_in'] !== null;
}

function has_check_out_today(mysqli $conn, int $employee_id, string $date): bool {
    $att = has_checked_in_today($conn, $employee_id, $date);
    return $att !== null && $att['check_out'] !== null;
}

// ─── Cross-Module Conflict Detection ──────────────────────────

function check_attendance_leave_conflict(mysqli $conn, int $employee_id, string $date): ?string {
    $att = has_checked_in_today($conn, $employee_id, $date);

    if ($att && $att['check_in'] && has_approved_leave_on_date($conn, $employee_id, $date)) {
        return 'Employee has both attendance check-in and approved leave on ' . $date . '. Please resolve the conflict.';
    }

    $has_pending = has_pending_leave_on_date($conn, $employee_id, $date);
    if ($att && $att['check_in'] && $has_pending) {
        return 'Employee has check-in and a pending leave request on ' . $date . '.';
    }

    return null;
}

function check_overtime_leave_conflict(mysqli $conn, int $employee_id, string $date): ?string {
    if (has_approved_leave_on_date($conn, $employee_id, $date)) {
        $ot_stmt = $conn->prepare("SELECT id FROM overtime_requests WHERE employee_id = ? AND ot_date = ? AND status IN ('Pending', 'Approved')");
        $ot_stmt->bind_param('is', $employee_id, $date);
        $ot_stmt->execute();
        $ot_stmt->store_result();
        if ($ot_stmt->num_rows > 0) {
            $ot_stmt->close();
            return 'Employee has overtime request on a date with approved leave.';
        }
        $ot_stmt->close();
    }
    return null;
}

function check_overtime_attendance_conflict(mysqli $conn, int $employee_id, string $date): ?string {
    $att = has_checked_in_today($conn, $employee_id, $date);
    $has_completed = $att && $att['check_in'] && $att['check_out'];

    if (!$has_completed) {
        $ot_stmt = $conn->prepare("SELECT id FROM overtime_requests WHERE employee_id = ? AND ot_date = ? AND status IN ('Pending', 'Approved')");
        $ot_stmt->bind_param('is', $employee_id, $date);
        $ot_stmt->execute();
        $ot_stmt->store_result();
        if ($ot_stmt->num_rows > 0) {
            $ot_stmt->close();
            return 'Employee has overtime request but no completed attendance (check-in & check-out) on ' . $date . '.';
        }
        $ot_stmt->close();
    }
    return null;
}

// ─── Status Display Helpers ────────────────────────────────────

function get_attendance_status_label(string $status): string {
    return match ($status) {
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'leave' => 'Approved Leave',
        'half_absent' => 'Half-Day Absent',
        'full_absent' => 'Full-Day Absent',
        'awol' => 'AWOL',
        'public_holiday' => 'Public Holiday',
        'weekend' => 'Weekend',
        default => ucfirst($status),
    };
}

function get_attendance_status_badge_class(string $status): string {
    return match ($status) {
        'present' => 'bg-emerald-500/20 text-emerald-400',
        'absent' => 'bg-red-500/20 text-red-400',
        'late' => 'bg-amber-500/20 text-amber-400',
        'leave' => 'bg-blue-500/20 text-blue-400',
        'half_absent' => 'bg-orange-500/20 text-orange-400',
        'full_absent' => 'bg-rose-600/20 text-rose-400',
        'awol' => 'bg-red-700/20 text-red-500',
        'public_holiday' => 'bg-pink-500/20 text-pink-400',
        'weekend' => 'bg-purple-500/20 text-purple-400',
        default => 'bg-white/10 text-zinc-300',
    };
}

function is_present_status(string $status): bool {
    return in_array($status, ['present', 'late']);
}

function is_absent_status(string $status): bool {
    return in_array($status, ['absent', 'full_absent', 'half_absent', 'awol']);
}

function is_non_working_status(string $status): bool {
    return in_array($status, ['weekend', 'public_holiday', 'leave']);
}

// ─── NRC (National Registration Card) Helpers ────────────────────

function get_nrc_state_codes(): array {
    return [
        '1'  => '1 - Kachin',
        '2'  => '2 - Kayah',
        '3'  => '3 - Kayin',
        '4'  => '4 - Chin',
        '5'  => '5 - Sagaing',
        '6'  => '6 - Tanintharyi',
        '7'  => '7 - Bago',
        '8'  => '8 - Magway',
        '9'  => '9 - Mandalay',
        '10' => '10 - Mon',
        '11' => '11 - Rakhine',
        '12' => '12 - Yangon',
        '13' => '13 - Shan',
        '14' => '14 - Ayeyarwady',
    ];
}

function get_nrc_township_codes(): array {
    return [
        '1' => ['AHGAYA', 'BAMANA', 'DAPHAYA', 'HAPANA', 'KAMANA', 'KAMATA', 'KAPATA', 'KHABADA', 'KHALAPHA', 'KHAPHANA', 'LAGANA', 'MAKANA', 'MAKATA', 'MAKHABA', 'MALANA', 'MAMANA', 'MANYANA', 'MASANA', 'NAMANA', 'PANADA', 'PATAAH', 'PAWANA', 'PHAKANA', 'SABATA', 'SADANA', 'SALANA', 'SAPABA', 'TANANA', 'WAMANA', 'YABAYA', 'YAKANA'],
        '2' => ['BALAKHA', 'DAMASA', 'LAKANA', 'MASANA', 'PHASANA', 'PHAYASA', 'YATANA', 'YATHANA'],
        '3' => ['BAAHNA', 'BAGALA', 'BATHASA', 'KADANA', 'KAKAYA', 'KAMAMA', 'KASAKA', 'LABANA', 'LATHANA', 'MAWATA', 'PAKANA', 'PHAPANA', 'SAKALA', 'THATAKA', 'THATANA', 'WALAMA', 'YAYATHA'],
        '4' => ['HAKHANA', 'HTATALA', 'KAKHANA', 'KAPALA', 'MATANA', 'MATAPA', 'PALAWA', 'PHALANA', 'SAMANA', 'TATANA', 'TAZANA', 'YAKHADA', 'YAZANA'],
        '5' => ['AHTANA', 'AHYATA', 'BAMANA', 'BATALA', 'DAHANA', 'DAPAYA', 'HAMALA', 'HSAMARA', 'HTAKHANA', 'HTAPAKHA', 'KABALA', 'KALAHTA', 'KALANA', 'KALATA', 'KALAWA', 'KAMANA', 'KANANA', 'KATHANA', 'KHAOUNA', 'KHAOUTA', 'KHAPANA', 'KHATANA', 'LAHANA', 'LAYANA', 'MAKANA', 'MALANA', 'MAMANA', 'MAMATA', 'MAPALA', 'MATHANA', 'MAYANA', 'NAYANA', 'PALABA', 'PALANA', 'PASANA', 'PHAPANA', 'SAKANA', 'SALAKA', 'TAMANA', 'TASANA', 'WALANA', 'WATHANA', 'YABANA', 'YAMAPA', 'YAOUNA'],
        '6' => ['BAPANA', 'HTAWANA', 'KALAAH', 'KASANA', 'KATHANA', 'KAYAYA', 'KHAMAKA', 'LALANA', 'MAMANA', 'MATANA', 'PAKAMA', 'PALANA', 'PALATA', 'TANATHA', 'TATHAYA', 'THAYAKHA', 'YAPHANA'],
        '7' => ['AHPHANA', 'AHTANA', 'DAOUNA', 'HTATAPA', 'KAKANA', 'KAPAKA', 'KATAKHA', 'KATATA', 'KAWANA', 'LAPATA', 'MADANA', 'MALANA', 'MANYANA', 'NATALA', 'NYALAPA', 'PAKHANA', 'PAKHATA', 'PAMANA', 'PANAKA', 'PATALA', 'PATANA', 'PATASA', 'PATATA', 'PHAMANA', 'TANGANA', 'THAKANA', 'THANAPA', 'THASANA', 'THAWATA', 'WAMANA', 'YAKANA', 'YATANA', 'YATAYA', 'ZAKANA'],
        '8' => ['AHLANA', 'GAGANA', 'HTALANA', 'KAHTANA', 'KAMANA', 'KHAMANA', 'MABANA', 'MAKANA', 'MALANA', 'MAMANA', 'MATANA', 'MATHANA', 'NAMANA', 'NGAPHANA', 'PAKHAKA', 'PAMANA', 'PAPHANA', 'SAKANA', 'SALANA', 'SAMANA', 'SAPAWA', 'SAPHANA', 'SATAYA', 'TATAKA', 'THAYANA', 'YANAKHA', 'YASAKA'],
        '9' => ['AHMAYA', 'AHMAZA', 'AUTATHA', 'DAKHATHA', 'KAMANA', 'KAPATA', 'KASANA', 'KHAAHZA', 'KHAMASA', 'LAWANA', 'MAHAMA', 'MAHTALA', 'MAKANA', 'MAKHANA', 'MALANA', 'MATAYA', 'MATHANA', 'NAHTAKA', 'NGATHAYA', 'NGAZANA', 'NYAOUNA', 'PABANA', 'PABATHA', 'PAKAKHA', 'PAMANA', 'PATHAKA', 'SAKANA', 'SAKATA', 'TAKANA', 'TAKATA', 'TATAOU', 'TATHANA', 'THAPAKA', 'THASANA', 'WATANA', 'YAMATHA', 'ZABATHA', 'ZAYATHA'],
        '10' => ['BALANA', 'KAHTANA', 'KAKHAMA', 'KAMAYA', 'KHASANA', 'KHAZANA', 'LAMANA', 'MADANA', 'MALAMA', 'PAMANA', 'THAHTANA', 'THAPHAYA', 'YAMANA'],
        '11' => ['AHMANA', 'BATHATA', 'GAMANA', 'KAPHANA', 'KATALA', 'KATANA', 'MAAHNA', 'MAAHTA', 'MAOUNA', 'MAPANA', 'MAPATA', 'PANAKA', 'PATANA', 'SATANA', 'TAKANA', 'TAPAWA', 'THATANA', 'YABANA', 'YATHATA'],
        '12' => ['AHLANA', 'AHSANA', 'BAHANA', 'BATAHTA', 'DAGAMA', 'DAGANA', 'DAGASA', 'DAGATA', 'DAGAYA', 'DALANA', 'DAPANA', 'HTATAPA', 'KAKAKA', 'KAKHAKA', 'KAMANA', 'KAMATA', 'KAMAYA', 'KATANA', 'KATATA', 'KHAYANA', 'LAKANA', 'LAMANA', 'LAMATA', 'LATHANA', 'LATHAYA', 'MABANA', 'MAGADA', 'MAGATA', 'MAYAKA', 'OUKAMA', 'OUKATA', 'PABATA', 'PAZATA', 'SAKANA', 'SAKHANA', 'TAKANA', 'TAMANA', 'TATAHTA', 'TATANA', 'THAGAKA', 'THAKATA', 'THAKHANA', 'THALANA', 'YAKANA', 'YAPATHA'],
        '13' => ['AHPANA', 'AHTANA', 'AHTHAYA', 'HAHANA', 'HAMANA', 'HAPANA', 'HAPATA', 'KAHANA', 'KAKANA', 'KAKHANA', 'KALADA', 'KALAHTA', 'KALANA', 'KALATA', 'KAMANA', 'KATALA', 'KATANA', 'KATATA', 'KATHANA', 'KHALANA', 'KHAYAHA', 'LAKANA', 'LAKHANA', 'LAKHATA', 'LALANA', 'LAYANA', 'MABANA', 'MAHAYA', 'MAHTANA', 'MAHTATA', 'MAKAHTA', 'MAKANA', 'MAKATA', 'MAKHANA', 'MAKHATA', 'MALANA', 'MALATA', 'MAMAHTA', 'MAMANA', 'MAMATA', 'MANANA', 'MANATA', 'MANGANA', 'MAPAHTA', 'MAPANA', 'MAPATA', 'MAPHANA', 'MAPHATA', 'MARATA', 'MASANA', 'MASATA', 'MATANA', 'MATATA', 'MAYAHTA', 'MAYANA', 'MAYATA', 'NAHSANA', 'NAKHANA', 'NAKHATA', 'NAMATA', 'NAPHANA', 'NATAYA', 'NYAYANA', 'PALAHTA', 'PALANA', 'PALATA', 'PASANA', 'PASATA', 'PATAYA', 'PAWANA', 'PAYANA', 'PHAKHANA', 'SASANA', 'TAKANA', 'TAKHALA', 'TALANA', 'TAMANYA', 'TATANA', 'TAYANA', 'THANANA', 'THAPANA', 'YANGANA', 'YANYANA', 'YASANA'],
        '14' => ['AHGAPA', 'AHMANA', 'AHMATA', 'BAKALA', 'DADAYA', 'DANAPHA', 'HAKAKA', 'HATHATA', 'KAKAHTA', 'KAKANA', 'KAKHANA', 'KALANA', 'KAPANA', 'LAMANA', 'LAPATA', 'MAAHNA', 'MAAHPA', 'MAMAKA', 'MAMANA', 'NGAPATA', 'NGASANA', 'NGATHAKHA', 'NGAYAKA', 'NYATANA', 'PASALA', 'PATANA', 'PHAPANA', 'THAPANA', 'WAKHAMA', 'YAKANA', 'YATHAYA', 'ZALANA'],
    ];
}

function get_nrc_citizenship_types(): array {
    return [
        'N' => '(N) - Citizen',
        'E' => '(E) - Associate Citizen',
        'P' => '(P) - Naturalized Citizen',
        'T' => '(T) - Temporary',
    ];
}

function get_nrc_religion_options(): array {
    return [
        'Buddhism'     => 'Buddhism',
        'Christianity' => 'Christianity',
        'Islam'        => 'Islam',
        'Hinduism'     => 'Hinduism',
        'No Religion'  => 'No Religion',
    ];
}

function build_nrc(string $state, string $township, string $citizenship, string $number): string {
    $state = trim($state);
    $township = trim($township);
    $citizenship = strtoupper(trim($citizenship));
    $number = trim($number);
    if (empty($state) || empty($township) || empty($citizenship) || empty($number)) {
        return '';
    }
    return $state . '/' . $township . '(' . $citizenship . ')' . str_pad($number, 6, '0', STR_PAD_LEFT);
}

function parse_nrc(string $nrc): array {
    $state = '';
    $township = '';
    $citizenship = '';
    $number = '';
    if (preg_match('/^(\d{1,2})\/([A-Za-z]+)\(([NEPT])\)(\d{1,6})$/', trim($nrc), $m)) {
        $state = $m[1];
        $township = $m[2];
        $citizenship = $m[3];
        $number = $m[4];
    }
    return [$state, $township, $citizenship, $number];
}
