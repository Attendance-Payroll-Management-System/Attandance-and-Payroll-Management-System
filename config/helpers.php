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
            SUM(CASE WHEN status = 'paid_leave' THEN 1 ELSE 0 END) as paid_leave_days,
            SUM(CASE WHEN status = 'unpaid_leave' THEN 1 ELSE 0 END) as unpaid_leave_days,
            SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days,
            SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as effective_present_days,
            SUM(CASE WHEN status IN ('awol', 'absent', 'full_absent', 'half_absent') THEN 1 ELSE 0 END) as absent_days_total,
            SUM(CASE WHEN status IN ('weekend', 'public_holiday') THEN 1 ELSE 0 END) as non_working_days,
            COALESCE(SUM(total_working_hours), 0) as total_hours_worked
         FROM attendance 
         WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: [
        'total_days' => 0, 'present_days' => 0, 'absent_days' => 0, 'late_days' => 0,
        'leave_days' => 0, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'half_days' => 0,
        'effective_present_days' => 0, 'absent_days_total' => 0, 'non_working_days' => 0,
        'total_hours_worked' => 0
    ];
}

function calculate_overtime_amount_for_payroll(mysqli $conn, int $employee_id, string $month_start, string $month_end, float $hourly_rate): float {
    $total_ot_amount = 0;

    // 1. From overtime_requests table
    $has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;

    if ($has_ot_type) {
        $stmt = $conn->prepare(
            "SELECT ot_date, total_hours, ot_type, ot_rate, ot_pay FROM overtime_requests
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'"
        );
        $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            if ($row['ot_pay'] !== null) {
                $total_ot_amount += (float)$row['ot_pay'];
            } else {
                $rate_multiplier = match ($row['ot_type'] ?? 'working_day') {
                    'holiday' => 0.04,
                    'weekend' => 0.03,
                    default => 0.02,
                };
                $total_ot_amount += $hourly_rate * $rate_multiplier * $row['total_hours'];
            }
        }
        $stmt->close();
    } else {
        // Fallback: old logic without ot_type column
        $stmt = $conn->prepare(
            "SELECT ot_date, start_time, end_time, total_hours FROM overtime_requests
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'"
        );
        $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $day_of_week = date('N', strtotime($row['ot_date']));
            $is_weekend = $day_of_week >= 6;
            $is_holiday = false;
            $h_check = $conn->prepare("SELECT COUNT(*) as cnt FROM holidays WHERE holiday_date = ?");
            $h_check->bind_param('s', $row['ot_date']);
            $h_check->execute();
            if ($h_check->get_result()->fetch_assoc()['cnt'] > 0) {
                $is_holiday = true;
            }
            $h_check->close();

            $rate_multiplier = match (true) {
                $is_holiday => 0.04,
                $is_weekend => 0.03,
                default => 0.02,
            };
            $total_ot_amount += $hourly_rate * $rate_multiplier * $row['total_hours'];
        }
        $stmt->close();
    }

    // 2. From overtime_records table (assignment module)
    $has_or = $conn->query("SHOW TABLES LIKE 'overtime_records'")->num_rows > 0;
    if ($has_or) {
        $or_stmt = $conn->prepare(
            "SELECT ot_pay FROM overtime_records
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved' AND payroll_id IS NULL"
        );
        $or_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
        $or_stmt->execute();
        $or_result = $or_stmt->get_result();
        while ($or_row = $or_result->fetch_assoc()) {
            $total_ot_amount += (float)$or_row['ot_pay'];
        }
        $or_stmt->close();
    }

    return round($total_ot_amount, 2);
}

// ─── Currency Helpers ─────────────────────────────────────────

function get_currency(mysqli $conn): string {
    return get_system_setting($conn, 'payroll_currency', 'K');
}

function format_currency(mysqli $conn, float $amount): string {
    $currency = get_currency($conn);
    return $currency . ' ' . number_format($amount, 0);
}

function format_currency_symbol(string $currency, float $amount): string {
    return $currency . ' ' . number_format($amount, 0);
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

function get_check_in_start_time(): string {
    return '08:30:00'; // Earliest allowed check-in time
}

function is_late_checkin(string $check_in_time): bool {
    $threshold = '09:00:00';
    return strtotime($check_in_time) > strtotime($threshold);
}

function is_before_check_in_start(string $time): bool {
    return strtotime($time) < strtotime(get_check_in_start_time());
}

function get_late_threshold(): string {
    return '09:00:00';
}

function get_work_start_time(): string {
    return '09:00:00'; // MMT official work start
}

function get_work_end_time(): string {
    return '17:00:00'; // MMT official work end (8-hour day)
}

function is_before_work_start(string $time): bool {
    return strtotime($time) < strtotime(get_work_start_time());
}

function is_after_work_end(string $time): bool {
    return strtotime($time) > strtotime(get_work_end_time());
}

// ─── Attendance Status Auto-Calculation ────────────────────────

function calculate_attendance_status(mysqli $conn, int $employee_id, string $date, ?string $check_in, ?string $check_out, ?float $total_hours): string {
    set_mmt_timezone();

    // Prerequisite: Weekend
    if (is_weekend($date)) {
        return 'weekend';
    }

    // Prerequisite: Public Holiday
    if (is_public_holiday($conn, $date)) {
        return 'public_holiday';
    }

    // Prerequisite: Approved Leave (distinguish paid vs unpaid)
    if (has_approved_leave_on_date($conn, $employee_id, $date)) {
        return get_leave_status_for_date($conn, $employee_id, $date);
    }

    // Priority 1: No Check-In and No Check-Out → Absent Without Leave (AWOL)
    if ($check_in === null) {
        return 'awol';
    }

    $check_in_time = $check_in ? date('H:i:s', strtotime($check_in)) : '';

    // Priority 2: Check-In between 12:00 PM and 5:00 PM → Half-Day (regardless of total working hours)
    if ($check_in_time && strtotime($check_in_time) >= strtotime('12:00:00') && strtotime($check_in_time) < strtotime('17:00:00')) {
        return 'half_day';
    }

    // Priority 3: Check-Out between 12:00 PM and 5:00 PM → Half-Day
    if ($check_out !== null) {
        $check_out_time = date('H:i:s', strtotime($check_out));
        if (strtotime($check_out_time) >= strtotime('12:00:00') && strtotime($check_out_time) < strtotime('17:00:00')) {
            return 'half_day';
        }
    }

    // Hours-based status calculation (when check-out exists)
    if ($check_out !== null && $total_hours !== null) {
        // Priority 4: Total Working Hours less than 7 Hours → Half-Day
        if ($total_hours < 7) {
            return 'half_day';
        }
        // Priority 5: Total Working Hours between 7 Hours and less than 8 Hours → Late
        if ($total_hours >= 7 && $total_hours < 8) {
            return 'late';
        }
        // Priority 6: Total Working Hours 8 Hours or more → Present
        return 'present';
    }

    // Has check-in but no check-out yet — use time-based estimation
    $current_time = mmt_time();
    $hours_so_far = max(0, (strtotime($current_time) - strtotime($check_in)) / 3600);

    if ($hours_so_far >= 8) {
        return 'present';
    } elseif ($hours_so_far >= 7) {
        return 'late';
    }

    return 'half_day';
}

function get_leave_status_for_date(mysqli $conn, int $employee_id, string $date): string {
    $stmt = $conn->prepare(
        "SELECT lr.leave_type, lr.is_paid, lr.leave_duration 
         FROM leave_requests lr 
         WHERE lr.employee_id = ? AND lr.status = 'Approved' 
         AND lr.start_date <= ? AND lr.end_date >= ? 
         LIMIT 1"
    );
    $stmt->bind_param('iss', $employee_id, $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $leave = $result->fetch_assoc();
    $stmt->close();

    if ($leave) {
        if ($leave['is_paid'] == 1 || strtolower($leave['leave_type']) === 'sick leave') {
            return 'paid_leave';
        }
        return 'unpaid_leave';
    }
    return 'leave';
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
    // Get current status before recalculation
    $old_stmt = $conn->prepare("SELECT status FROM attendance WHERE id = ?");
    $old_stmt->bind_param('i', $attendance_id);
    $old_stmt->execute();
    $old_row = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();
    $old_status = $old_row['status'] ?? '';

    $new_status = calculate_attendance_status($conn, $employee_id, $date, $check_in, $check_out, $total_hours);

    $is_late_flag = is_late_checkin($check_in) ? 1 : 0;

    $stmt = $conn->prepare(
        "UPDATE attendance SET status = ?, is_late = ?, auto_calculated = 1, total_working_hours = ? WHERE id = ?"
    );
    $stmt->bind_param('sidi', $new_status, $is_late_flag, $total_hours, $attendance_id);
    $stmt->execute();
    $stmt->close();

    // Handle AWOL deduction on status change
    if ($old_status !== $new_status) {
        handle_awol_deduction_on_status_change($conn, $employee_id, $new_status, $attendance_id, $date);
    }

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
        'paid_leave' => 0,
        'unpaid_leave' => 0,
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
        } else {
            // Check for approved leave and distinguish paid vs unpaid
            $leave_stmt = $conn->prepare(
                "SELECT leave_type, is_paid FROM leave_requests 
                 WHERE employee_id = ? AND status = 'Approved' 
                 AND start_date <= ? AND end_date >= ? LIMIT 1"
            );
            $leave_stmt->bind_param('iss', $eid, $date, $date);
            $leave_stmt->execute();
            $leave_result = $leave_stmt->get_result();
            $leave = $leave_result->fetch_assoc();
            $leave_stmt->close();

            if ($leave) {
                $status = ($leave['is_paid'] == 1 || strtolower($leave['leave_type']) === 'sick leave') ? 'paid_leave' : 'unpaid_leave';
                $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, status, auto_calculated) VALUES (?, ?, ?, 1)");
                $stmt->bind_param('iss', $eid, $date, $status);
                $stmt->execute();
                $stmt->close();
                $result[$status]++;
            } elseif (!has_pending_leave_on_date($conn, $eid, $date)) {
                $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, status, auto_calculated) VALUES (?, ?, 'awol', 1)");
                $stmt->bind_param('is', $eid, $date);
                $stmt->execute();
                $awol_att_id = $conn->insert_id;
                $stmt->close();
                $result['awol']++;

                // Auto-create Pension Fund deduction for AWOL
                create_awol_deduction($conn, $eid, $awol_att_id, $date);
            }
        }
    }

    return $result;
}

// ─── Automatic Deductions for Attendance Violations ────────────

define('AWOL_DEDUCTION_RATE', 0.02);
define('AWOL_DEDUCTION_REMARKS', 'Auto Pension Fund Deduction for Unauthorized Absence');
define('HALF_DAY_DEDUCTION_REMARKS', 'Auto Half-Day Deduction');
define('UNPAID_ABSENCE_DEDUCTION_REMARKS', 'Auto Unpaid Absence Deduction');
define('SYSTEM_MANAGED_REMARK_PREFIX', 'Auto ');

// ─── Automatic Check-Out Helpers ──────────────────────────────

define('AUTO_CHECKOUT_TIME', '17:30:00');
define('AUTO_CHECKOUT_REMARKS', 'System auto checkout at end of work day');

/**
 * Get the approved overtime end time for an employee on a specific date.
 * Returns the end_time string if an approved OT exists, null otherwise.
 */
function get_approved_overtime_end_time(mysqli $conn, int $employee_id, string $date): ?string {
    $stmt = $conn->prepare(
        "SELECT end_time FROM overtime_requests 
         WHERE employee_id = ? AND ot_date = ? AND status = 'Approved'
         ORDER BY end_time DESC LIMIT 1"
    );
    $stmt->bind_param('is', $employee_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['end_time'] : null;
}

/**
 * Get the configured auto-checkout time from settings.
 */
function get_auto_checkout_time(): string {
    return AUTO_CHECKOUT_TIME;
}

/**
 * Process automatic check-out for employees who checked in but forgot to check out.
 * Skips employees with approved overtime requests (allows them to work until OT end time).
 * Sets is_auto_checkout = 1 to flag auto-generated check-outs.
 *
 * @param mysqli $conn  Database connection
 * @param string $date  The date to process (YYYY-MM-DD)
 * @return array  Summary of processing results
 */
function process_auto_checkout(mysqli $conn, string $date): array {
    set_mmt_timezone();

    $result = [
        'processed' => 0,
        'auto_checkout' => 0,
        'skipped_ot' => 0,
        'skipped_no_checkout' => 0,
        'errors' => [],
    ];

    // Check if auto-checkout feature is enabled
    $enabled = get_company_policy($conn, 'auto_checkout_enabled', '1');
    if ($enabled !== '1') {
        return $result;
    }

    $auto_checkout_time = get_auto_checkout_time();

    // Find all employees who checked in but haven't checked out on the given date
    $stmt = $conn->prepare(
        "SELECT a.id, a.employee_id, a.check_in, a.check_out, a.is_auto_checkout
         FROM attendance a
         WHERE a.attendance_date = ? 
         AND a.check_in IS NOT NULL 
         AND a.check_out IS NULL
         AND a.is_auto_checkout = 0"
    );
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($records as $record) {
        $result['processed']++;
        $att_id = (int)$record['id'];
        $eid = (int)$record['employee_id'];

        // Check if employee has an approved overtime request for this date
        $ot_end_time = get_approved_overtime_end_time($conn, $eid, $date);

        if ($ot_end_time !== null) {
            // Employee has approved OT — skip auto checkout, allow attendance until OT end
            $result['skipped_ot']++;
            continue;
        }

        // No approved OT — auto check-out at configured time (17:30 MMT)
        $check_in = $record['check_in'];
        $check_in_ts = strtotime($check_in);
        $auto_checkout_ts = strtotime("$date $auto_checkout_time");
        $total_seconds = max(0, $auto_checkout_ts - $check_in_ts);
        $total_hours = round($total_seconds / 3600, 2);

        // Build the auto checkout datetime
        $auto_checkout_datetime = "$date $auto_checkout_time";

        // Update attendance record with auto checkout
        $stmt = $conn->prepare(
            "UPDATE attendance SET check_out = ?, total_working_hours = ?, is_auto_checkout = 1 WHERE id = ?"
        );
        $stmt->bind_param('sdi', $auto_checkout_datetime, $total_hours, $att_id);

        if ($stmt->execute()) {
            $stmt->close();

            // Recalculate attendance status
            $new_status = recalculate_attendance_after_checkout(
                $conn, $att_id, $eid, $date, $check_in, $auto_checkout_datetime, $total_hours
            );

            // Log the auto checkout
            log_activity(
                $conn, $eid, 'auto_checkout',
                "Automatic check-out at $auto_checkout_time for attendance on $date. Hours: $total_hours. Status: $new_status"
            );

            $result['auto_checkout']++;
        } else {
            $result['errors'][] = "Failed to auto-checkout employee ID $eid on $date";
            $stmt->close();
        }
    }

    return $result;
}

/**
 * Render the "Auto Checked Out" badge HTML if the record was auto checked out.
 */
function get_auto_checkout_badge($is_auto_checkout): string {
    if (!$is_auto_checkout) return '';
    return '<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-violet-500/15 text-violet-400 border border-violet-500/20 ml-1.5"><i class="fa-solid fa-clock-rotate-left text-[8px]"></i>Auto Checked Out</span>';
}

function get_awol_deduction_type_id(mysqli $conn): ?int {
    ensure_deduction_types_exist($conn);
    $result = $conn->query("SELECT id FROM deduction_types WHERE deduction_name = 'Unpaid Absence' LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['id'];
    }
    return null;
}

function has_awol_deduction(mysqli $conn, int $employee_id, string $date): bool {
    $remarks = UNPAID_ABSENCE_DEDUCTION_REMARKS;
    $old_remarks = AWOL_DEDUCTION_REMARKS;
    $stmt = $conn->prepare("SELECT id FROM deductions WHERE employee_id = ? AND deduction_date = ? AND remarks IN (?, ?) LIMIT 1");
    $stmt->bind_param('isss', $employee_id, $date, $remarks, $old_remarks);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function create_awol_deduction(mysqli $conn, int $employee_id, int $attendance_id, string $date): bool {
    if (has_awol_deduction($conn, $employee_id, $date)) {
        return false;
    }

    $emp_stmt = $conn->prepare("SELECT basic_salary, name, employee_code FROM employee WHERE id = ?");
    $emp_stmt->bind_param('i', $employee_id);
    $emp_stmt->execute();
    $emp = $emp_stmt->get_result()->fetch_assoc();
    $emp_stmt->close();

    $basic_salary = (float)($emp['basic_salary'] ?? 0);
    if ($basic_salary <= 0) return false;

    // Calculate full day salary deduction
    $working_days = get_working_days_in_month((int)date('Y', strtotime($date)), (int)date('m', strtotime($date)));
    $daily_rate = calculate_daily_salary($basic_salary, $working_days);
    $deduction_amount = $daily_rate; // One full day salary deduction

    $type_id = get_awol_deduction_type_id($conn);

    $remarks = UNPAID_ABSENCE_DEDUCTION_REMARKS;
    $description = "Auto Unpaid Absence Deduction - One Full Day Salary (\$" . number_format($deduction_amount, 2) . ")";
    $stmt = $conn->prepare("INSERT INTO deductions (employee_id, title, deduction_type_id, attendance_id, amount, description, deduction_date, remarks) VALUES (?, 'Unpaid Absence', ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iiidsss', $employee_id, $type_id, $attendance_id, $deduction_amount, $description, $date, $remarks);
    $result = $stmt->execute();
    $stmt->close();

    if ($result) {
        log_awol_deduction($conn, $employee_id, $emp['name'] ?? '', $emp['employee_code'] ?? '', $date, $basic_salary, $deduction_amount);
    }

    return $result;
}

function remove_awol_deduction(mysqli $conn, int $employee_id, string $date): bool {
    $remarks = UNPAID_ABSENCE_DEDUCTION_REMARKS;
    $old_remarks = AWOL_DEDUCTION_REMARKS;
    $stmt = $conn->prepare("DELETE FROM deductions WHERE employee_id = ? AND deduction_date = ? AND remarks IN (?, ?)");
    $stmt->bind_param('isss', $employee_id, $date, $remarks, $old_remarks);
    $result = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected > 0;
}

function handle_awol_deduction_on_status_change(mysqli $conn, int $employee_id, string $new_status, int $attendance_id, string $date): void {
    $awol_statuses = ['awol', 'absent', 'full_absent'];
    $half_day_statuses = ['half_day'];

    if (in_array($new_status, $awol_statuses)) {
        create_awol_deduction($conn, $employee_id, $attendance_id, $date);
        remove_half_day_deduction($conn, $employee_id, $date);
    } elseif (in_array($new_status, $half_day_statuses)) {
        create_half_day_deduction($conn, $employee_id, $attendance_id, $date);
        remove_awol_deduction($conn, $employee_id, $date);
    } else {
        remove_awol_deduction($conn, $employee_id, $date);
        remove_half_day_deduction($conn, $employee_id, $date);
    }
}

// ─── Half-Day Deduction Helpers ───────────────────────────────

function ensure_deduction_types_exist(mysqli $conn): void {
    $types = ['Half-Day Deduction', 'Unpaid Absence'];
    foreach ($types as $type_name) {
        $check = $conn->query("SELECT id FROM deduction_types WHERE deduction_name = '$type_name' LIMIT 1");
        if (!$check || $check->num_rows === 0) {
            $conn->query("INSERT INTO deduction_types (deduction_name) VALUES ('$type_name')");
        }
    }
}

function is_system_managed_deduction(?string $remarks): bool {
    return !empty($remarks) && str_starts_with($remarks, SYSTEM_MANAGED_REMARK_PREFIX);
}

function get_half_day_deduction_type_id(mysqli $conn): ?int {
    ensure_deduction_types_exist($conn);
    $result = $conn->query("SELECT id FROM deduction_types WHERE deduction_name = 'Half-Day Deduction' LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['id'];
    }
    return null;
}

function has_half_day_deduction(mysqli $conn, int $employee_id, string $date): bool {
    $remarks = HALF_DAY_DEDUCTION_REMARKS;
    $stmt = $conn->prepare("SELECT id FROM deductions WHERE employee_id = ? AND deduction_date = ? AND remarks = ? LIMIT 1");
    $stmt->bind_param('iss', $employee_id, $date, $remarks);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function create_half_day_deduction(mysqli $conn, int $employee_id, int $attendance_id, string $date): bool {
    if (has_half_day_deduction($conn, $employee_id, $date)) {
        return false;
    }

    $emp_stmt = $conn->prepare("SELECT basic_salary, name, employee_code FROM employee WHERE id = ?");
    $emp_stmt->bind_param('i', $employee_id);
    $emp_stmt->execute();
    $emp = $emp_stmt->get_result()->fetch_assoc();
    $emp_stmt->close();

    $basic_salary = (float)($emp['basic_salary'] ?? 0);
    if ($basic_salary <= 0) return false;

    // Calculate half day salary deduction
    $working_days = get_working_days_in_month((int)date('Y', strtotime($date)), (int)date('m', strtotime($date)));
    $daily_rate = calculate_daily_salary($basic_salary, $working_days);
    $deduction_amount = round($daily_rate * 0.5, 2); // Half day salary deduction

    $type_id = get_half_day_deduction_type_id($conn);

    $remarks = HALF_DAY_DEDUCTION_REMARKS;
    $description = "Auto Half-Day Deduction - Half Day Salary (\$" . number_format($deduction_amount, 2) . ")";
    $stmt = $conn->prepare("INSERT INTO deductions (employee_id, title, deduction_type_id, attendance_id, amount, description, deduction_date, remarks) VALUES (?, 'Half-Day Deduction', ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iiidsss', $employee_id, $type_id, $attendance_id, $deduction_amount, $description, $date, $remarks);
    $result = $stmt->execute();
    $stmt->close();

    if ($result) {
        log_half_day_deduction($conn, $employee_id, $emp['name'] ?? '', $emp['employee_code'] ?? '', $date, $basic_salary, $deduction_amount);
    }

    return $result;
}

function remove_half_day_deduction(mysqli $conn, int $employee_id, string $date): bool {
    $remarks = HALF_DAY_DEDUCTION_REMARKS;
    $stmt = $conn->prepare("DELETE FROM deductions WHERE employee_id = ? AND deduction_date = ? AND remarks = ?");
    $stmt->bind_param('iss', $employee_id, $date, $remarks);
    $result = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected > 0;
}

function log_half_day_deduction(mysqli $conn, int $employee_id, string $employee_name, string $employee_code, string $date, float $basic_salary, float $deduction_amount): void {
    $res = $conn->query("SHOW TABLES LIKE 'activity_logs'");
    if (!$res || $res->num_rows === 0) return;

    $description = sprintf(
        'Half-Day Deduction created for %s (%s) | Attendance Date: %s | Basic Salary: %s | Deduction Amount: %s (Half Day) | Timestamp: %s',
        $employee_name,
        $employee_code,
        $date,
        number_format($basic_salary, 2),
        number_format($deduction_amount, 2),
        mmt_datetime()
    );

    $stmt = $conn->prepare("INSERT INTO activity_logs (employee_id, action, description, ip_address, user_agent) VALUES (?, 'half_day_deduction_created', ?, 'system', 'auto_deduction')");
    $stmt->bind_param('is', $employee_id, $description);
    $stmt->execute();
    $stmt->close();
}

function log_awol_deduction(mysqli $conn, int $employee_id, string $employee_name, string $employee_code, string $date, float $basic_salary, float $deduction_amount): void {
    $res = $conn->query("SHOW TABLES LIKE 'activity_logs'");
    if (!$res || $res->num_rows === 0) return;

    $description = sprintf(
        'Unpaid Absence Deduction created for %s (%s) | Attendance Date: %s | Basic Salary: %s | Deduction Amount: %s (Full Day) | Timestamp: %s',
        $employee_name,
        $employee_code,
        $date,
        number_format($basic_salary, 2),
        number_format($deduction_amount, 2),
        mmt_datetime()
    );

    $stmt = $conn->prepare("INSERT INTO activity_logs (employee_id, action, description, ip_address, user_agent) VALUES (?, 'awol_deduction_created', ?, 'system', 'auto_deduction')");
    $stmt->bind_param('is', $employee_id, $description);
    $stmt->execute();
    $stmt->close();
}

function reconcile_awol_deductions(mysqli $conn, string $date): array {
    set_mmt_timezone();

    $result = [
        'checked' => 0,
        'created' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    // Reconcile AWOL deductions
    $awol_stmt = $conn->prepare(
        "SELECT a.id as attendance_id, a.employee_id, a.status
         FROM attendance a
         WHERE a.attendance_date = ? AND a.status IN ('awol', 'absent', 'full_absent')"
    );
    $awol_stmt->bind_param('s', $date);
    $awol_stmt->execute();
    $awol_records = $awol_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $awol_stmt->close();

    foreach ($awol_records as $record) {
        $result['checked']++;
        $eid = (int)$record['employee_id'];
        $att_id = (int)$record['attendance_id'];

        if (has_awol_deduction($conn, $eid, $date)) {
            $result['skipped']++;
            continue;
        }

        $created = create_awol_deduction($conn, $eid, $att_id, $date);
        if ($created) {
            $result['created']++;
        } else {
            $result['errors'][] = "Failed to create AWOL deduction for employee ID $eid on $date";
        }
    }

    // Reconcile Half-Day deductions
    $half_day_stmt = $conn->prepare(
        "SELECT a.id as attendance_id, a.employee_id, a.status
         FROM attendance a
         WHERE a.attendance_date = ? AND a.status = 'half_day'"
    );
    $half_day_stmt->bind_param('s', $date);
    $half_day_stmt->execute();
    $half_day_records = $half_day_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $half_day_stmt->close();

    foreach ($half_day_records as $record) {
        $result['checked']++;
        $eid = (int)$record['employee_id'];
        $att_id = (int)$record['attendance_id'];

        if (has_half_day_deduction($conn, $eid, $date)) {
            $result['skipped']++;
            continue;
        }

        $created = create_half_day_deduction($conn, $eid, $att_id, $date);
        if ($created) {
            $result['created']++;
        } else {
            $result['errors'][] = "Failed to create Half-Day deduction for employee ID $eid on $date";
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
        'paid_leave' => 'Paid Leave',
        'unpaid_leave' => 'Unpaid Leave',
        'half_day' => 'Half Day',
        'half_absent' => 'Half-Day Absent',
        'full_absent' => 'Full-Day Absent',
        'awol' => 'AWOL',
        'public_holiday' => 'Public Holiday',
        'weekend' => 'Weekend',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function get_attendance_status_badge_class(string $status): string {
    return match ($status) {
        'present' => 'bg-emerald-500/20 text-emerald-400',
        'absent' => 'bg-red-500/20 text-red-400',
        'late' => 'bg-amber-500/20 text-amber-400',
        'leave' => 'bg-blue-500/20 text-blue-400',
        'paid_leave' => 'bg-sky-500/20 text-sky-400',
        'unpaid_leave' => 'bg-orange-500/20 text-orange-400',
        'half_day' => 'bg-teal-500/20 text-teal-400',
        'half_absent' => 'bg-orange-500/20 text-orange-400',
        'full_absent' => 'bg-rose-600/20 text-rose-400',
        'awol' => 'bg-red-700/20 text-red-500',
        'public_holiday' => 'bg-pink-500/20 text-pink-400',
        'weekend' => 'bg-purple-500/20 text-purple-400',
        default => 'bg-white/10 text-zinc-300',
    };
}

function is_present_status(string $status): bool {
    return in_array($status, ['present', 'late', 'half_day']);
}

function is_absent_status(string $status): bool {
    return in_array($status, ['absent', 'full_absent', 'half_absent', 'awol']);
}

function is_non_working_status(string $status): bool {
    return in_array($status, ['weekend', 'public_holiday', 'paid_leave', 'leave']);
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

// ─── Activity Log ───────────────────────────────────────────────
/**
 * Log user activity for audit trail
 *
 * @param mysqli $conn    Database connection
 * @param int    $emp_id  Employee ID (null for system actions)
 * @param string $action  Action performed (e.g., 'login', 'update_profile', 'approve_leave')
 * @param string $description  Human-readable description
 * @return bool  True on success
 *
 * Usage examples:
 *   log_activity($conn, $admin_id, 'login', 'Admin logged in');
 *   log_activity($conn, $emp_id, 'update_profile', 'Profile updated by user');
 *   log_activity($conn, null, 'system_backup', 'Daily backup completed');
 */
function log_activity($conn, $emp_id, $action, $description = '') {
    // Check if table exists
    $res = $conn->query("SHOW TABLES LIKE 'activity_logs'");
    if (!$res || $res->num_rows === 0) {
        return false;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $conn->prepare("INSERT INTO activity_logs (employee_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $emp_id, $action, $description, $ip, $ua);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Get activity logs with optional filters
 *
 * @param mysqli $conn     Database connection
 * @param int    $limit    Number of records to return (default 50)
 * @param int    $emp_id   Filter by employee ID (null for all)
 * @param string $action   Filter by action type (null for all)
 * @return array  Activity logs
 */
// ─── Payroll Calculation Helpers ─────────────────────────────

function calculate_daily_salary(float $basic_salary, int $working_days): float {
    return $working_days > 0 ? round($basic_salary / $working_days, 2) : 0;
}

function calculate_hourly_rate(float $basic_salary, int $working_days, float $hours_per_day = 8): float {
    $monthly_hours = $working_days * $hours_per_day;
    return $monthly_hours > 0 ? round($basic_salary / $monthly_hours, 2) : 0;
}

function get_monthly_attendance_summary(mysqli $conn, int $employee_id, string $month_start, string $month_end): array {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days,
            SUM(CASE WHEN status IN ('paid_leave', 'leave') THEN 1 ELSE 0 END) as paid_leave_days,
            SUM(CASE WHEN status = 'unpaid_leave' THEN 1 ELSE 0 END) as unpaid_leave_days,
            SUM(CASE WHEN status IN ('awol', 'absent', 'full_absent', 'half_absent') THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN status = 'weekend' THEN 1 ELSE 0 END) as weekend_days,
            SUM(CASE WHEN status = 'public_holiday' THEN 1 ELSE 0 END) as holiday_days,
            COALESCE(SUM(total_working_hours), 0) as total_hours_worked,
            SUM(CASE WHEN status IN ('present', 'late', 'half_day') THEN 1 ELSE 0 END) as working_days_present
         FROM attendance 
         WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: [
        'total_days' => 0, 'present_days' => 0, 'late_days' => 0, 'half_days' => 0,
        'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'absent_days' => 0,
        'weekend_days' => 0, 'holiday_days' => 0, 'total_hours_worked' => 0,
        'working_days_present' => 0
    ];
}

function calculate_payroll_for_employee(mysqli $conn, int $employee_id, int $month, int $year): array {
    $month_start = sprintf('%04d-%02d-01', $year, $month);
    $month_end = date('Y-m-t', strtotime($month_start));
    $working_days = get_working_days_in_month($year, $month);

    // Get employee data
    $emp_stmt = $conn->prepare("SELECT basic_salary FROM employee WHERE id = ?");
    $emp_stmt->bind_param('i', $employee_id);
    $emp_stmt->execute();
    $emp = $emp_stmt->get_result()->fetch_assoc();
    $emp_stmt->close();

    $basic = (float)($emp['basic_salary'] ?? 0);
    $daily_rate = calculate_daily_salary($basic, $working_days);
    $hourly_rate = calculate_hourly_rate($basic, $working_days);

    // Get attendance summary
    $att = get_monthly_attendance_summary($conn, $employee_id, $month_start, $month_end);

    // Get allowance from personal info
    $allow_stmt = $conn->prepare("SELECT COALESCE(epi.allowance, 0) as allowance FROM employee_personal_info epi WHERE epi.employee_id = ?");
    $allow_stmt->bind_param('i', $employee_id);
    $allow_stmt->execute();
    $allow_row = $allow_stmt->get_result()->fetch_assoc();
    $allow_stmt->close();
    $allowance = (float)($allow_row['allowance'] ?? 0);

    // Calculate deductions
    $absent_deduction = $daily_rate * (int)$att['absent_days'];
    $half_day_deduction = $daily_rate * 0.5 * (int)$att['half_days'];
    $unpaid_leave_deduction = $daily_rate * (int)$att['unpaid_leave_days'];
    $late_penalty_rate = (float)get_company_policy($conn, 'late_penalty_per_occurrence', 0);
    $late_deduction = $late_penalty_rate * (int)$att['late_days'];

    // Total attendance-based deduction
    $total_attendance_deduction = $absent_deduction + $half_day_deduction + $unpaid_leave_deduction + $late_deduction;

    // Overtime
    $ot_amount = calculate_overtime_amount_for_payroll($conn, $employee_id, $month_start, $month_end, $hourly_rate);

    // Overtime breakdown by type
    $ot_breakdown = get_overtime_payroll_breakdown($conn, $employee_id, $month_start, $month_end, $hourly_rate);

    // Bonuses
    $bonus_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM bonuses WHERE employee_id = ? AND bonus_date BETWEEN ? AND ?");
    $bonus_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $bonus_stmt->execute();
    $bonus_amount = (float)$bonus_stmt->get_result()->fetch_assoc()['total'];
    $bonus_stmt->close();

    // Other deductions
    $ded_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM deductions WHERE employee_id = ? AND deduction_date BETWEEN ? AND ?");
    $ded_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $ded_stmt->execute();
    $other_deductions = (float)$ded_stmt->get_result()->fetch_assoc()['total'];
    $ded_stmt->close();

    // Net salary calculation
    $gross = $basic + $allowance + $ot_amount + $bonus_amount;
    $net = $gross - $total_attendance_deduction - $other_deductions;

    return [
        'basic_salary' => $basic,
        'allowance_amount' => $allowance,
        'ot_amount' => $ot_amount,
        'bonus_amount' => $bonus_amount,
        'absent_deduction' => $absent_deduction,
        'half_day_deduction' => $half_day_deduction,
        'unpaid_leave_deduction' => $unpaid_leave_deduction,
        'late_deduction' => $late_deduction,
        'total_attendance_deduction' => $total_attendance_deduction,
        'other_deductions' => $other_deductions,
        'gross_salary' => $gross,
        'net_salary' => $net,
        'working_days' => $working_days,
        'present_days' => (int)$att['present_days'],
        'half_days' => (int)$att['half_days'],
        'late_days' => (int)$att['late_days'],
        'absent_days' => (int)$att['absent_days'],
        'paid_leave_days' => (int)$att['paid_leave_days'],
        'unpaid_leave_days' => (int)$att['unpaid_leave_days'],
        'overtime_hours' => (float)($att['total_hours_worked'] ?? 0),
        'daily_rate' => $daily_rate,
        'hourly_rate' => $hourly_rate,
        'ot_breakdown' => $ot_breakdown,
    ];
}

function get_activity_logs($conn, $limit = 50, $emp_id = null, $action = null) {
    $res = $conn->query("SHOW TABLES LIKE 'activity_logs'");
    if (!$res || $res->num_rows === 0) {
        return [];
    }

    $where = [];
    $params = [];
    $types = '';

    if ($emp_id !== null) {
        $where[] = "al.employee_id = ?";
        $params[] = $emp_id;
        $types .= 'i';
    }
    if ($action !== null) {
        $where[] = "al.action = ?";
        $params[] = $action;
        $types .= 's';
    }

    $sql = "SELECT al.*, e.name as employee_name
            FROM activity_logs al
            LEFT JOIN employee e ON al.employee_id = e.id";

    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY al.created_at DESC LIMIT " . (int)$limit;

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $result = $conn->query($sql);
        $logs = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    return $logs;
}

// ─── Payroll Enterprise Helpers ──────────────────────────────

function get_payroll_setting(mysqli $conn, string $key, string $default = ''): string {
    $stmt = $conn->prepare("SELECT setting_value FROM payroll_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['setting_value'] ?? $default;
}

function get_salary_structure(mysqli $conn, int $employee_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM salary_structures WHERE employee_id = ? AND status = 'Active' ORDER BY effective_date DESC LIMIT 1");
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: null;
}

function get_salary_structure_history(mysqli $conn, int $employee_id): array {
    $stmt = $conn->prepare("SELECT ss.*, a.name as created_by_name FROM salary_structures ss LEFT JOIN employee a ON ss.created_by = a.id WHERE ss.employee_id = ? ORDER BY ss.effective_date DESC");
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function calculate_salary_structure(mysqli $conn, int $employee_id, float $basic_salary, int $working_days): array {
    $daily = $working_days > 0 ? round($basic_salary / $working_days, 2) : 0;
    $hourly = $working_days > 0 ? round($basic_salary / ($working_days * 8), 2) : 0;
    $ot_rate = round($hourly * 1.5, 2);
    return [
        'basic_salary' => $basic_salary,
        'daily_salary_rate' => $daily,
        'hourly_salary_rate' => $hourly,
        'overtime_rate_per_hour' => $ot_rate,
    ];
}

function create_salary_structure(mysqli $conn, int $employee_id, float $basic_salary, string $effective_date, int $created_by): bool {
    $working_days = (int)get_payroll_setting($conn, 'payroll_working_days_per_month', '22');
    $rates = calculate_salary_structure($conn, $employee_id, $basic_salary, $working_days);
    $stmt = $conn->prepare("INSERT INTO salary_structures (employee_id, basic_salary, daily_salary_rate, hourly_salary_rate, overtime_rate_per_hour, effective_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'Active', ?)");
    $stmt->bind_param('idddsi', $employee_id, $rates['basic_salary'], $rates['daily_salary_rate'], $rates['hourly_salary_rate'], $rates['overtime_rate_per_hour'], $effective_date, $created_by);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function generate_payroll_for_employee(mysqli $conn, int $employee_id, int $month, int $year, string $status = 'Generated', ?int $admin_id = null): ?int {
    $payroll = calculate_payroll_for_employee($conn, $employee_id, $month, $year);
    $total_deductions = $payroll['total_attendance_deduction'] + $payroll['other_deductions'];
    $month_start = sprintf('%04d-%02d-01', $year, $month);

    $upsert = $conn->prepare("INSERT INTO payrolls (employee_id, payroll_month, payroll_year, basic_salary, ot_amount, allowance_amount, bonus_amount, deduction_amount, tax_amount, leave_deduction, late_deduction, unpaid_leave_deduction, gross_salary, net_salary, working_days, present_days, half_days, late_days, absent_days, paid_leave_days, unpaid_leave_days, overtime_hours, generated_date, status, generated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
        ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary), ot_amount=VALUES(ot_amount), allowance_amount=VALUES(allowance_amount),
        bonus_amount=VALUES(bonus_amount), deduction_amount=VALUES(deduction_amount), leave_deduction=VALUES(leave_deduction),
        late_deduction=VALUES(late_deduction), unpaid_leave_deduction=VALUES(unpaid_leave_deduction),
        gross_salary=VALUES(gross_salary), net_salary=VALUES(net_salary), working_days=VALUES(working_days),
        present_days=VALUES(present_days), half_days=VALUES(half_days), late_days=VALUES(late_days),
        absent_days=VALUES(absent_days), paid_leave_days=VALUES(paid_leave_days), unpaid_leave_days=VALUES(unpaid_leave_days),
        overtime_hours=VALUES(overtime_hours), generated_date=CURDATE(), status=VALUES(status), generated_by=VALUES(generated_by)");
    $upsert->bind_param('iiidddddddddddiiiiiiidsi',
        $employee_id, $month, $year, $payroll['basic_salary'], $payroll['ot_amount'],
        $payroll['allowance_amount'], $payroll['bonus_amount'], $total_deductions,
        $payroll['unpaid_leave_deduction'], $payroll['late_deduction'], $payroll['unpaid_leave_deduction'],
        $payroll['gross_salary'], $payroll['net_salary'], $payroll['working_days'],
        $payroll['present_days'], $payroll['half_days'], $payroll['late_days'],
        $payroll['absent_days'], $payroll['paid_leave_days'], $payroll['unpaid_leave_days'],
        $payroll['overtime_hours'], $status, $admin_id
    );
    $upsert->execute();
    $payroll_id = $conn->insert_id;
    if ($payroll_id <= 0) {
        $stmt = $conn->prepare("SELECT id FROM payroll WHERE employee_id = ? AND pay_month = ? AND pay_year = ?");
        $stmt->bind_param('iii', $employee_id, $month, $year);
        $stmt->execute();
        $payroll_id = (int)$stmt->get_result()->fetch_assoc()['id'];
        $stmt->close();
    }
    $upsert->close();

    if ($payroll_id > 0) {
        $conn->query("DELETE FROM payroll_details WHERE payroll_id = $payroll_id");
        $detail_stmt = $conn->prepare("INSERT INTO payroll_details (payroll_id, component_type, component_name, amount) VALUES (?, ?, ?, ?)");
        $components = [
            ['earning', 'Basic Salary', $payroll['basic_salary']],
            ['earning', 'Allowance', $payroll['allowance_amount']],
            ['earning', 'Overtime Pay', $payroll['ot_amount']],
            ['earning', 'Bonuses', $payroll['bonus_amount']],
            ['deduction', 'Absent Deduction (' . $payroll['absent_days'] . ' days)', $payroll['absent_deduction']],
            ['deduction', 'Half-Day Deduction (' . $payroll['half_days'] . ' days)', $payroll['half_day_deduction']],
            ['deduction', 'Unpaid Leave Deduction (' . $payroll['unpaid_leave_days'] . ' days)', $payroll['unpaid_leave_deduction']],
            ['deduction', 'Late Deduction (' . $payroll['late_days'] . ' occurrences)', $payroll['late_deduction']],
            ['deduction', 'Other Deductions', $payroll['other_deductions']],
        ];
        foreach ($components as $comp) {
            if ($comp[2] > 0) {
                $detail_stmt->bind_param('issd', $payroll_id, $comp[0], $comp[1], $comp[2]);
                $detail_stmt->execute();
            }
        }
        $detail_stmt->close();
    }
    return $payroll_id;
}

function update_payroll_status(mysqli $conn, int $payroll_id, string $new_status, ?int $admin_id = null, string $remarks = ''): bool {
    $stmt = $conn->prepare("SELECT payment_status FROM payroll WHERE id = ?");
    $stmt->bind_param('i', $payroll_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$current) return false;

    $from_status = $current['status'];

    $date_col = match($new_status) {
        'Reviewed' => 'reviewed_date',
        'Approved' => 'approved_date',
        'Paid' => 'paid_date',
        'Cancelled' => 'cancelled_date',
        default => null
    };

    $sql = "UPDATE payrolls SET status = ?" . ($date_col ? ", $date_col = CURDATE()" : "") . ($admin_id ? ", reviewed_by = COALESCE(reviewed_by, ?), approved_by = COALESCE(approved_by, ?), paid_by = COALESCE(paid_by, ?)" : "") . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($admin_id) {
        $stmt->bind_param('siiii', $new_status, $admin_id, $admin_id, $admin_id, $payroll_id);
    } else {
        $stmt->bind_param('si', $new_status, $payroll_id);
    }
    $stmt->execute();
    $stmt->close();

    $log = $conn->prepare("INSERT INTO payroll_approvals (payroll_id, from_status, to_status, action_by, remarks) VALUES (?, ?, ?, ?, ?)");
    $log->bind_param('issis', $payroll_id, $from_status, $new_status, $admin_id, $remarks);
    $log->execute();
    $log->close();

    return true;
}

function add_payroll_notification(mysqli $conn, ?int $employee_id, string $type, string $title, string $message, ?int $payroll_id = null): bool {
    $stmt = $conn->prepare("INSERT INTO payroll_notifications (employee_id, notification_type, title, message, payroll_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('isssi', $employee_id, $type, $title, $message, $payroll_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function get_unread_notification_count(mysqli $conn, ?int $employee_id = null): int {
    if ($employee_id) {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM payroll_notifications WHERE (employee_id = ? OR employee_id IS NULL) AND is_read = 0");
        $stmt->bind_param('i', $employee_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM payroll_notifications WHERE is_read = 0");
    }
    $stmt->execute();
    $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $cnt;
}

function get_payroll_status_badge(string $status): string {
    $map = [
        'Draft' => 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20',
        'Generated' => 'bg-blue-500/15 text-blue-400 border-blue-500/20',
        'Reviewed' => 'bg-cyan-500/15 text-cyan-400 border-cyan-500/20',
        'Approved' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
        'Paid' => 'bg-purple-500/15 text-purple-400 border-purple-500/20',
        'Cancelled' => 'bg-rose-500/15 text-rose-400 border-rose-500/20',
    ];
    return $map[$status] ?? 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20';
}

function get_payroll_status_icon(string $status): string {
    $map = [
        'Draft' => 'fa-pen-to-square',
        'Generated' => 'fa-calculator',
        'Reviewed' => 'fa-magnifying-glass',
        'Approved' => 'fa-check-circle',
        'Paid' => 'fa-sack-dollar',
        'Cancelled' => 'fa-ban',
    ];
    return $map[$status] ?? 'fa-circle';
}

function get_payroll_details_with_components(mysqli $conn, int $payroll_id): ?array {
    $stmt = $conn->prepare("
        SELECT p.*, e.name, e.employee_code, e.basic_salary as emp_salary, e.email,
               d.department_name, pos.position_name
        FROM payroll p
        JOIN employee e ON p.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions pos ON e.position_id = pos.id
        WHERE p.id = ?
    ");
    $stmt->bind_param('i', $payroll_id);
    $stmt->execute();
    $payroll = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payroll) return null;

    $det_stmt = $conn->prepare("SELECT component_type, component_name, amount FROM payroll_details WHERE payroll_id = ? ORDER BY id");
    $det_stmt->bind_param('i', $payroll_id);
    $det_stmt->execute();
    $payroll['details'] = $det_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $det_stmt->close();

    $app_stmt = $conn->prepare("SELECT pa.*, a.name as action_by_name FROM payroll_approvals pa LEFT JOIN employee a ON pa.action_by = a.id WHERE pa.payroll_id = ? ORDER BY pa.created_at ASC");
    $app_stmt->bind_param('i', $payroll_id);
    $app_stmt->execute();
    $payroll['approvals'] = $app_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $app_stmt->close();

    return $payroll;
}

function get_payroll_months_with_data(mysqli $conn): array {
    $result = $conn->query("SELECT DISTINCT pay_month, pay_year FROM payroll ORDER BY pay_year DESC, pay_month DESC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function get_dashboard_payroll_stats(mysqli $conn): array {
    $current_month = (int)mmt_date('m');
    $current_year = (int)mmt_date('Y');
    $month_start = sprintf('%04d-%02d-01', $current_year, $current_month);
    $month_end = date('Y-m-t', strtotime($month_start));

    $stats = [];

    // Total employees
    $res = $conn->query("SELECT COUNT(*) as cnt FROM employee WHERE status = 'active'");
    $stats['total_employees'] = (int)$res->fetch_assoc()['cnt'];

    // Current month payroll totals (from `payroll` table)
    $stmt = $conn->prepare("SELECT COUNT(*) as total_payrolls, COALESCE(SUM(net_salary),0) as total_net, COALESCE(SUM(overtime_amount),0) as total_ot, COALESCE(SUM(bonus),0) as total_bonus, COALESCE(SUM(total_deduction),0) as total_ded FROM payroll WHERE pay_month = ? AND pay_year = ?");
    $stmt->bind_param('ii', $current_month, $current_year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stats['total_payrolls'] = (int)$row['total_payrolls'];
    $stats['total_net'] = (float)$row['total_net'];
    $stats['total_ot'] = (float)$row['total_ot'];
    $stats['total_bonus'] = (float)$row['total_bonus'];
    $stats['total_ded'] = (float)$row['total_ded'];

    // Status counts
    $status_counts = ['Draft' => 0, 'Generated' => 0, 'Reviewed' => 0, 'Approved' => 0, 'Paid' => 0, 'Cancelled' => 0];
    $res = $conn->query("SELECT payment_status, COUNT(*) as cnt FROM payroll WHERE pay_month = $current_month AND pay_year = $current_year GROUP BY payment_status");
    if ($res) while ($r = $res->fetch_assoc()) {
        $s = $r['payment_status'];
        if (isset($status_counts[$s])) $status_counts[$s] = (int)$r['cnt'];
        elseif ($s === 'Pending') $status_counts['Generated'] += (int)$r['cnt'];
    }
    $stats['status_counts'] = $status_counts;
    $stats['total_pending'] = $status_counts['Generated'] + $status_counts['Draft'];
    $stats['total_paid'] = $status_counts['Paid'];
    $stats['total_approved'] = $status_counts['Approved'];

    // Monthly trend (last 6 months)
    $trend = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = $current_month - $i;
        $y = $current_year;
        if ($m < 1) { $m += 12; $y--; }
        $stmt = $conn->prepare("SELECT COALESCE(SUM(net_salary),0) as total FROM payroll WHERE pay_month = ? AND pay_year = ?");
        $stmt->bind_param('ii', $m, $y);
        $stmt->execute();
        $trend[] = ['month' => date('M', mktime(0,0,0,$m,1)), 'year' => $y, 'total' => (float)$stmt->get_result()->fetch_assoc()['total']];
        $stmt->close();
    }
    $stats['monthly_trend'] = $trend;

    return $stats;
}

function get_payroll_report_data(mysqli $conn, string $start_date, string $end_date, ?int $department_id = null, ?int $employee_id = null, ?string $status = null): array {
    $where = ["p.pay_year >= ? AND p.pay_year <= ?"];
    $params = [(int)date('Y', strtotime($start_date)), (int)date('Y', strtotime($end_date))];
    $types = 'ii';

    if ($department_id) {
        $where[] = "e.department_id = ?";
        $params[] = $department_id;
        $types .= 'i';
    }
    if ($employee_id) {
        $where[] = "p.employee_id = ?";
        $params[] = $employee_id;
        $types .= 'i';
    }
    if ($status) {
        $where[] = "p.payment_status = ?";
        $params[] = $status;
        $types .= 's';
    }

    $sql = "SELECT p.*, e.name, e.employee_code, d.department_name, pos.position_name
            FROM payroll p
            JOIN employee e ON p.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN positions pos ON e.position_id = pos.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.pay_year DESC, p.pay_month DESC, e.name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $data;
}

function export_to_csv(array $data, array $headers, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($data as $row) {
        $line = [];
        foreach ($headers as $h) {
            $key = strtolower(str_replace(' ', '_', $h));
            $line[] = $row[$key] ?? $row[array_search($h, array_keys($row))] ?? '';
        }
        fputcsv($output, $line);
    }
    fclose($output);
    exit;
}

function export_to_excel(array $data, array $headers, string $filename): void {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo '<table border="1">';
    echo '<tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr>';
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $val) {
            echo '<td>' . htmlspecialchars((string)$val) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

// ─── Overtime Enterprise Helpers ──────────────────────────

function get_overtime_setting(mysqli $conn, string $key, string $default = ''): string {
    $table_check = $conn->query("SHOW TABLES LIKE 'overtime_settings'");
    if (!$table_check || $table_check->num_rows === 0) return $default;
    $stmt = $conn->prepare("SELECT setting_value FROM overtime_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['setting_value'] ?? $default;
}

function detect_overtime_type(mysqli $conn, string $ot_date): string {
    $day_of_week = (int)date('N', strtotime($ot_date));
    if ($day_of_week >= 6) return 'weekend';
    $h_check = $conn->prepare("SELECT COUNT(*) as cnt FROM holidays WHERE holiday_date = ?");
    $h_check->bind_param('s', $ot_date);
    $h_check->execute();
    $is_holiday = $h_check->get_result()->fetch_assoc()['cnt'] > 0;
    $h_check->close();
    if ($is_holiday) return 'holiday';
    return 'working_day';
}

function get_overtime_rate_for_type(string $ot_type): float {
    return match ($ot_type) {
        'holiday' => 0.04,
        'weekend' => 0.03,
        default => 0.02,
    };
}

function get_overtime_time_window(mysqli $conn, string $ot_type): array {
    $prefix = match ($ot_type) {
        'weekend' => 'ot_weekend',
        'holiday' => 'ot_holiday',
        default => 'ot_working_day',
    };
    return [
        'start' => get_overtime_setting($conn, $prefix . '_start', match ($ot_type) {
            'weekend', 'holiday' => '09:00',
            default => '17:00',
        }),
        'end' => get_overtime_setting($conn, $prefix . '_end', match ($ot_type) {
            'weekend', 'holiday' => '17:00',
            default => '21:00',
        }),
    ];
}

function get_overtime_max_hours(mysqli $conn, string $ot_type): float {
    $key = match ($ot_type) {
        'weekend' => 'ot_weekend_max_hours',
        'holiday' => 'ot_holiday_max_hours',
        default => 'ot_working_day_max_hours',
    };
    return (float)get_overtime_setting($conn, $key, match ($ot_type) {
        'weekend', 'holiday' => '8',
        default => '4',
    });
}

function validate_overtime_request_rules(mysqli $conn, int $employee_id, string $ot_date, string $start_time, string $end_time, string $reason, int $exclude_id = 0, string $request_type = 'employee_request'): array {
    $errors = [];
    set_mmt_timezone();

    $status_error = validate_employee_active($conn, $employee_id);
    if ($status_error) { $errors[] = $status_error; return $errors; }

    if (empty($ot_date) || empty($start_time) || empty($end_time)) {
        $errors[] = 'Please fill in all required fields.';
        return $errors;
    }

    // Past date validation
    $today = mmt_date();
    if ($ot_date < $today) {
        $errors[] = 'You cannot submit an overtime request for a past date.';
        return $errors;
    }

    if (strtotime($end_time) <= strtotime($start_time)) {
        $errors[] = 'End time must be later than start time.';
    }

    $start_ts = strtotime($start_time);
    $end_ts = strtotime($end_time);
    $total_hours = round(($end_ts - $start_ts) / 3600, 2);
    if ($total_hours <= 0) {
        $errors[] = 'Overtime hours must be greater than zero.';
    }

    if (has_approved_leave_on_date($conn, $employee_id, $ot_date)) {
        $errors[] = 'Cannot request overtime on a date with approved leave.';
    }

    $absent_statuses = ['awol', 'absent', 'full_absent', 'half_absent'];
    $att = has_checked_in_today($conn, $employee_id, $ot_date);
    if ($att && in_array(strtolower($att['status'] ?? ''), $absent_statuses)) {
        $errors[] = 'Cannot request overtime. Your attendance status is Absent for this date.';
    }

    // Check overlapping OT
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

    // Detect type and validate time window + max hours
    $ot_type = detect_overtime_type($conn, $ot_date);
    $window = get_overtime_time_window($conn, $ot_type);
    $time_in = date('H:i', $start_ts);
    $time_out = date('H:i', $end_ts);

    if ($time_in < $window['start']) {
        $errors[] = "Overtime start time cannot be before {$window['start']} for " . str_replace('_', ' ', $ot_type) . " OT.";
    }
    if ($time_out > $window['end']) {
        $errors[] = "Overtime end time cannot be after {$window['end']} for " . str_replace('_', ' ', $ot_type) . " OT.";
    }

    $max_hours = get_overtime_max_hours($conn, $ot_type);
    if ($total_hours > $max_hours) {
        $errors[] = "Overtime hours cannot exceed $max_hours hours for " . str_replace('_', ' ', $ot_type) . " OT.";
    }

    // Monthly cap
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
    $monthly_used = (float)($m_row['total'] ?? 0);
    $monthly_max = (float)get_overtime_setting($conn, 'ot_monthly_max_hours', '60');
    if (($monthly_used + $total_hours) > $monthly_max) {
        $remaining = max(0, $monthly_max - $monthly_used);
        $errors[] = "Would exceed monthly OT cap ($monthly_max h). You have $remaining h remaining this month.";
    }

    return $errors;
}

function calculate_overtime_pay_for_request(mysqli $conn, int $employee_id, string $ot_type, float $total_hours): float {
    $emp_stmt = $conn->prepare("SELECT basic_salary FROM employee WHERE id = ?");
    $emp_stmt->bind_param('i', $employee_id);
    $emp_stmt->execute();
    $emp = $emp_stmt->get_result()->fetch_assoc();
    $emp_stmt->close();
    $basic = (float)($emp['basic_salary'] ?? 0);

    $working_days = (int)get_overtime_setting($conn, 'payroll_working_days_per_month', '22');
    if ($working_days <= 0) $working_days = 22;
    $hourly_rate = $working_days > 0 ? round($basic / ($working_days * 8), 2) : 0;
    $rate_multiplier = get_overtime_rate_for_type($ot_type);

    return round($hourly_rate * $rate_multiplier * $total_hours, 2);
}

function log_overtime_action(mysqli $conn, int $overtime_id, string $action, int $action_by, string $action_by_type = 'admin', ?array $old_values = null, ?array $new_values = null, ?string $remarks = null): void {
    $table_check = $conn->query("SHOW TABLES LIKE 'overtime_logs'");
    if (!$table_check || $table_check->num_rows === 0) return;

    $stmt = $conn->prepare("INSERT INTO overtime_logs (overtime_id, action, action_by, action_by_type, old_values, new_values, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $old_json = $old_values ? json_encode($old_values) : null;
    $new_json = $new_values ? json_encode($new_values) : null;
    $stmt->bind_param('isissss', $overtime_id, $action, $action_by, $action_by_type, $old_json, $new_json, $remarks);
    $stmt->execute();
    $stmt->close();
}

function check_monthly_overtime_remaining(mysqli $conn, int $employee_id, string $ot_date): array {
    $month_start = date('Y-m-01', strtotime($ot_date));
    $month_end = date('Y-m-t', strtotime($ot_date));

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(CASE WHEN status IN ('Approved','Pending') THEN total_hours ELSE 0 END), 0) as used_hours,
                COUNT(*) as total_requests,
                COALESCE(SUM(CASE WHEN status = 'Approved' THEN total_hours ELSE 0 END), 0) as approved_hours,
                COALESCE(SUM(CASE WHEN status = 'Pending' THEN total_hours ELSE 0 END), 0) as pending_hours
         FROM overtime_requests 
         WHERE employee_id = ? AND ot_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $monthly_max = (float)get_overtime_setting($conn, 'ot_monthly_max_hours', '60');
    $used = (float)($row['used_hours'] ?? 0);
    return [
        'monthly_max' => $monthly_max,
        'used_hours' => $used,
        'remaining_hours' => max(0, $monthly_max - $used),
        'approved_hours' => (float)($row['approved_hours'] ?? 0),
        'pending_hours' => (float)($row['pending_hours'] ?? 0),
        'total_requests' => (int)($row['total_requests'] ?? 0),
    ];
}

function get_overtime_dashboard_stats(mysqli $conn, ?int $month = null, ?int $year = null): array {
    $month = $month ?? (int)date('m');
    $year = $year ?? (int)date('Y');
    $month_start = sprintf('%04d-%02d-01', $year, $month);
    $month_end = date('Y-m-t', strtotime($month_start));

    $has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;

    // Total OT hours for the month
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(total_hours), 0) as total_hours,
                COALESCE(SUM(CASE WHEN status = 'Approved' THEN total_hours ELSE 0 END), 0) as approved_hours,
                COALESCE(SUM(CASE WHEN status = 'Pending' THEN total_hours ELSE 0 END), 0) as pending_hours,
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count
         FROM overtime_requests 
         WHERE ot_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $month_start, $month_end);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // OT by type
    $by_type = [];
    if ($has_ot_type) {
        $res = $conn->query("SELECT ot_type, COALESCE(SUM(total_hours), 0) as hours, COUNT(*) as count 
                              FROM overtime_requests 
                              WHERE ot_date BETWEEN '$month_start' AND '$month_end' AND status = 'Approved'
                              GROUP BY ot_type");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $by_type[$r['ot_type']] = ['hours' => (float)$r['hours'], 'count' => (int)$r['count']];
            }
        }
    }

    // OT by request type (employee vs admin)
    $has_request_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'request_type'")->num_rows > 0;
    $by_request_type = [];
    if ($has_request_type) {
        $res2 = $conn->query("SELECT request_type, COALESCE(SUM(total_hours), 0) as hours, COUNT(*) as count,
                              COALESCE(SUM(ot_pay), 0) as total_pay
                              FROM overtime_requests
                              WHERE ot_date BETWEEN '$month_start' AND '$month_end' AND status = 'Approved'
                              GROUP BY request_type");
        if ($res2) {
            while ($r = $res2->fetch_assoc()) {
                $by_request_type[$r['request_type']] = ['hours' => (float)$r['hours'], 'count' => (int)$r['count'], 'pay' => (float)$r['total_pay']];
            }
        }
    }
    $stats['by_request_type'] = $by_request_type;

    // Total OT earnings for the month
    $earn_check = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_pay'")->num_rows > 0;
    if ($earn_check) {
        $earn_res = $conn->query("SELECT COALESCE(SUM(ot_pay), 0) as total_earnings FROM overtime_requests WHERE ot_date BETWEEN '$month_start' AND '$month_end' AND status = 'Approved'");
        $stats['total_earnings'] = $earn_res ? (float)$earn_res->fetch_assoc()['total_earnings'] : 0;
    } else {
        $stats['total_earnings'] = 0;
    }

    // Top employees by OT
    $top_emp = $conn->prepare(
        "SELECT otr.employee_id, e.name, e.employee_code, d.department_name,
                COALESCE(SUM(otr.total_hours), 0) as total_hours
         FROM overtime_requests otr
         JOIN employee e ON otr.employee_id = e.id
         LEFT JOIN departments d ON e.department_id = d.id
         WHERE otr.ot_date BETWEEN ? AND ? AND otr.status = 'Approved'
         GROUP BY otr.employee_id, e.name, e.employee_code, d.department_name
         ORDER BY total_hours DESC
         LIMIT 10"
    );
    $top_emp->bind_param('ss', $month_start, $month_end);
    $top_emp->execute();
    $top_employees = $top_emp->get_result()->fetch_all(MYSQLI_ASSOC);
    $top_emp->close();

    // Daily OT trend
    $daily_trend = $conn->prepare(
        "SELECT otr.ot_date, COALESCE(SUM(otr.total_hours), 0) as hours, COUNT(*) as requests
         FROM overtime_requests otr
         WHERE otr.ot_date BETWEEN ? AND ? AND otr.status = 'Approved'
         GROUP BY otr.ot_date
         ORDER BY otr.ot_date ASC"
    );
    $daily_trend->bind_param('ss', $month_start, $month_end);
    $daily_trend->execute();
    $trend_data = $daily_trend->get_result()->fetch_all(MYSQLI_ASSOC);
    $daily_trend->close();

    $stats['by_type'] = $by_type;
    $stats['top_employees'] = $top_employees;
    $stats['daily_trend'] = $trend_data;
    $stats['month'] = $month;
    $stats['year'] = $year;

    return $stats;
}

function get_overtime_payroll_breakdown(mysqli $conn, int $employee_id, string $month_start, string $month_end, float $hourly_rate): array {
    $has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;

    $breakdown = [
        'working_day' => ['hours' => 0, 'amount' => 0, 'count' => 0],
        'weekend' => ['hours' => 0, 'amount' => 0, 'count' => 0],
        'holiday' => ['hours' => 0, 'amount' => 0, 'count' => 0],
        'total_hours' => 0,
        'total_amount' => 0,
        'details' => [],
    ];

    if ($has_ot_type) {
        $stmt = $conn->prepare(
            "SELECT id, ot_date, total_hours, ot_type, ot_rate, ot_pay 
             FROM overtime_requests 
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'
             ORDER BY ot_date"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT id, ot_date, total_hours 
             FROM overtime_requests 
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'
             ORDER BY ot_date"
        );
    }
    $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $type = $row['ot_type'] ?? detect_overtime_type($conn, $row['ot_date']);
        $rate = $row['ot_rate'] !== null ? (float)$row['ot_rate'] : get_overtime_rate_for_type($type);
        $pay = $row['ot_pay'] !== null ? (float)$row['ot_pay'] : $hourly_rate * $rate * (float)$row['total_hours'];
        $hours = (float)$row['total_hours'];

        if (isset($breakdown[$type])) {
            $breakdown[$type]['hours'] += $hours;
            $breakdown[$type]['amount'] += $pay;
            $breakdown[$type]['count']++;
        }
        $breakdown['total_hours'] += $hours;
        $breakdown['total_amount'] += $pay;
        $breakdown['details'][] = [
            'id' => $row['id'],
            'date' => $row['ot_date'],
            'hours' => $hours,
            'type' => $type,
            'rate' => $rate,
            'pay' => $pay,
        ];
    }

    return $breakdown;
}

function get_overtime_type_badge(string $ot_type): string {
    return match ($ot_type) {
        'working_day' => '<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold bg-blue-500/20 text-blue-400">Working Day</span>',
        'weekend' => '<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-500/20 text-amber-400">Weekend</span>',
        'holiday' => '<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold bg-rose-500/20 text-rose-400">Holiday</span>',
        default => '<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold bg-zinc-500/20 text-zinc-400">' . htmlspecialchars($ot_type) . '</span>',
    };
}

function get_overtime_report_data(mysqli $conn, string $from_date, string $to_date, ?int $department_id = null, ?int $employee_id = null, ?string $status = null, ?string $request_type = null): array {
    $where = ["otr.ot_date BETWEEN ? AND ?"];
    $params = [$from_date, $to_date];
    $types = 'ss';

    if ($department_id) {
        $where[] = "e.department_id = ?";
        $params[] = $department_id;
        $types .= 'i';
    }
    if ($employee_id) {
        $where[] = "otr.employee_id = ?";
        $params[] = $employee_id;
        $types .= 'i';
    }
    if ($status) {
        $where[] = "otr.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    if ($request_type) {
        $has_rt = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'request_type'")->num_rows > 0;
        if ($has_rt) {
            $where[] = "otr.request_type = ?";
            $params[] = $request_type;
            $types .= 's';
        }
    }

    $has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;
    $has_request_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'request_type'")->num_rows > 0;
    $ot_cols = $has_ot_type ? ', otr.ot_type, otr.ot_rate, otr.ot_pay' : '';
    if ($has_request_type) $ot_cols .= ', otr.request_type';

    $has_assigned_by = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'assigned_by_id'")->num_rows > 0;

    $sql = "SELECT otr.*$ot_cols, e.name as employee_name, e.employee_code, d.department_name, p.position_name
            FROM overtime_requests otr
            JOIN employee e ON otr.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN positions p ON e.position_id = p.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY otr.ot_date DESC, e.name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $data;
}

function get_overtime_earnings_summary(mysqli $conn, int $employee_id, string $month_start, string $month_end): array {
    $has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;

    if ($has_ot_type) {
        $stmt = $conn->prepare(
            "SELECT ot_type, COALESCE(SUM(total_hours), 0) as total_hours, 
                    COALESCE(SUM(ot_pay), 0) as total_pay,
                    COUNT(*) as count
             FROM overtime_requests 
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'
             GROUP BY ot_type"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(total_hours), 0) as total_hours, 
                    COUNT(*) as count
             FROM overtime_requests 
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'"
        );
    }
    $stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $summary = ['total_hours' => 0, 'total_pay' => 0, 'by_type' => []];
    foreach ($rows as $r) {
        if ($has_ot_type) {
            $summary['by_type'][$r['ot_type']] = [
                'hours' => (float)$r['total_hours'],
                'pay' => (float)$r['total_pay'],
                'count' => (int)$r['count'],
            ];
            $summary['total_hours'] += (float)$r['total_hours'];
            $summary['total_pay'] += (float)$r['total_pay'];
        } else {
            $summary['total_hours'] += (float)$r['total_hours'];
        }
    }
    return $summary;
}

// ─── Overtime Assignment Module Helpers ──────────────────────

/**
 * Generate a unique assignment code like OTA-2026-07-001
 */
function generate_assignment_code(mysqli $conn): string {
    set_mmt_timezone();
    $prefix = 'OTA-' . date('Y-m') . '-';
    $stmt = $conn->prepare("SELECT assignment_code FROM overtime_assignments WHERE assignment_code LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $last_num = (int)substr($row['assignment_code'], -3);
        $new_num = $last_num + 1;
    } else {
        $new_num = 1;
    }
    return $prefix . str_pad($new_num, 3, '0', STR_PAD_LEFT);
}

/**
 * Validate a single employee for overtime assignment on a specific date.
 * Returns ['valid' => bool, 'errors' => [], 'warnings' => []]
 */
function validate_employee_for_overtime(mysqli $conn, int $employee_id, string $ot_date, bool $check_attendance = true): array {
    $errors = [];
    $warnings = [];
    set_mmt_timezone();

    // 1. Employee exists and is active
    $stmt = $conn->prepare("SELECT id, status, name FROM employee WHERE id = ?");
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $emp = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$emp) {
        $errors[] = 'Employee record not found.';
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }

    $status = strtolower(trim($emp['status'] ?? ''));
    if ($status !== 'active') {
        $errors[] = 'Employee is ' . ucfirst($status) . '. Overtime cannot be assigned.';
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }

    // 2. Check if employee is on approved leave
    if (has_approved_leave_on_date($conn, $employee_id, $ot_date)) {
        $errors[] = 'Employee is on approved leave for this date.';
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }

    // Attendance checks (skippable for department pre-assignment)
    if ($check_attendance) {
        // 3. Check attendance record exists
        $att = has_checked_in_today($conn, $employee_id, $ot_date);
        if ($att === null) {
            $errors[] = 'Employee must attend work before overtime can be assigned.';
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // 4. Must have check-in
        if (empty($att['check_in'])) {
            $errors[] = 'Employee must check in before overtime can be assigned.';
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // 5. Must have check-out
        if (empty($att['check_out'])) {
            $errors[] = 'Employee must check out before overtime can be assigned.';
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // 6. Check attendance status is not absent/AWOL
        $att_status = strtolower($att['status'] ?? '');
        if (in_array($att_status, ['awol', 'absent', 'full_absent', 'half_absent'])) {
            $errors[] = 'Employee is absent. Overtime assignment is not allowed.';
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }
    }

    return ['valid' => true, 'errors' => $errors, 'warnings' => $warnings];
}

/**
 * Validate overtime time rules based on OT type.
 * Returns ['valid' => bool, 'errors' => []]
 */
function validate_overtime_time_rules(string $ot_type, string $start_time, string $end_time): array {
    $errors = [];

    $start_ts = strtotime($start_time);
    $end_ts = strtotime($end_time);

    if ($end_ts <= $start_ts) {
        $errors[] = 'End time must be after start time.';
        return ['valid' => false, 'errors' => $errors];
    }

    $total_hours = round(($end_ts - $start_ts) / 3600, 2);
    $time_in = date('H:i', $start_ts);
    $time_out = date('H:i', $end_ts);

    switch ($ot_type) {
        case 'working_day':
            if ($time_in < '17:00') {
                $errors[] = 'Working day OT start time must be at or after 5:00 PM.';
            }
            if ($time_out > '21:00') {
                $errors[] = 'Working day OT end time must be at or before 9:00 PM.';
            }
            if ($total_hours > 4) {
                $errors[] = 'Working day OT cannot exceed 4 hours per day.';
            }
            break;

        case 'weekend':
        case 'holiday':
            $label = $ot_type === 'weekend' ? 'Weekend' : 'Holiday';
            if ($time_in < '09:00') {
                $errors[] = "$label OT start time must be at or after 9:00 AM.";
            }
            if ($time_out > '17:00') {
                $errors[] = "$label OT end time must be at or before 5:00 PM.";
            }
            if ($total_hours > 8) {
                $errors[] = "$label OT cannot exceed 8 hours per day.";
            }
            break;
    }

    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Check if employee would exceed monthly OT limit (60h).
 * Returns ['within_limit' => bool, 'current_hours' => float, 'new_total' => float, 'max' => float, 'error' => string]
 */
function check_monthly_ot_limit(mysqli $conn, int $employee_id, string $ot_date, float $new_hours): array {
    $month_start = date('Y-m-01', strtotime($ot_date));
    $month_end = date('Y-m-t', strtotime($ot_date));

    // Check overtime_requests table
    $stmt1 = $conn->prepare(
        "SELECT COALESCE(SUM(total_hours), 0) as total FROM overtime_requests
         WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status IN ('Approved', 'Pending')"
    );
    $stmt1->bind_param('iss', $employee_id, $month_start, $month_end);
    $stmt1->execute();
    $row1 = $stmt1->get_result()->fetch_assoc();
    $stmt1->close();
    $ot_requests_hours = (float)($row1['total'] ?? 0);

    // Check overtime_records table
    $has_or_table = $conn->query("SHOW TABLES LIKE 'overtime_records'")->num_rows > 0;
    $ot_records_hours = 0;
    if ($has_or_table) {
        $stmt2 = $conn->prepare(
            "SELECT COALESCE(SUM(total_hours), 0) as total FROM overtime_records
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status IN ('Approved', 'Pending')"
        );
        $stmt2->bind_param('iss', $employee_id, $month_start, $month_end);
        $stmt2->execute();
        $row2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $ot_records_hours = (float)($row2['total'] ?? 0);
    }

    $current_hours = $ot_requests_hours + $ot_records_hours;
    $max_monthly = (float)get_overtime_setting($conn, 'ot_monthly_max_hours', '60');
    $new_total = $current_hours + $new_hours;
    $within_limit = $new_total <= $max_monthly;

    $error = '';
    if (!$within_limit) {
        $remaining = max(0, $max_monthly - $current_hours);
        $error = "Monthly overtime limit exceeded. Current: {$current_hours}h, New: {$new_hours}h, Total: {$new_total}h, Max: {$max_monthly}h. Remaining: {$remaining}h.";
    }

    return [
        'within_limit' => $within_limit,
        'current_hours' => $current_hours,
        'new_total' => $new_total,
        'max' => $max_monthly,
        'error' => $error,
    ];
}

/**
 * Check for duplicate/overlapping OT assignments for an employee.
 * Returns true if a duplicate exists.
 */
function check_duplicate_assignment(mysqli $conn, int $employee_id, string $ot_date, string $start_time, string $end_time, int $exclude_assignment_id = 0): bool {
    // Check overtime_requests table
    $stmt = $conn->prepare(
        "SELECT id FROM overtime_requests
         WHERE employee_id = ? AND ot_date = ? AND id != ?
         AND status IN ('Pending', 'Approved')
         AND start_time < ? AND end_time > ?"
    );
    $exclude_id = 0; // overtime_requests uses different ID space
    $stmt->bind_param('isisi', $employee_id, $ot_date, $exclude_id, $end_time, $start_time);
    $stmt->execute();
    $stmt->store_result();
    $has_dup = $stmt->num_rows > 0;
    $stmt->close();

    if ($has_dup) return true;

    // Check overtime_records table
    $has_or = $conn->query("SHOW TABLES LIKE 'overtime_records'")->num_rows > 0;
    if ($has_or) {
        $stmt2 = $conn->prepare(
            "SELECT id FROM overtime_records
             WHERE employee_id = ? AND ot_date = ?
             AND status IN ('Approved', 'Pending')
             AND start_time < ? AND end_time > ?"
        );
        $stmt2->bind_param('isis', $employee_id, $ot_date, $end_time, $start_time);
        $stmt2->execute();
        $stmt2->store_result();
        $has_dup = $stmt2->num_rows > 0;
        $stmt2->close();
        if ($has_dup) return true;
    }

    // Check overtime_assignments -> overtime_assignment_employees for pending/assigned
    $has_oae = $conn->query("SHOW TABLES LIKE 'overtime_assignment_employees'")->num_rows > 0;
    if ($has_oae) {
        $stmt3 = $conn->prepare(
            "SELECT oae.id FROM overtime_assignment_employees oae
             JOIN overtime_assignments oa ON oae.assignment_id = oa.id
             WHERE oae.employee_id = ? AND oa.ot_date = ? AND oa.id != ?
             AND oae.status IN ('Assigned', 'Accepted')
             AND oa.start_time < ? AND oa.end_time > ?"
        );
        $stmt3->bind_param('isisi', $employee_id, $ot_date, $exclude_assignment_id, $end_time, $start_time);
        $stmt3->execute();
        $stmt3->store_result();
        $has_dup = $stmt3->num_rows > 0;
        $stmt3->close();
        if ($has_dup) return true;
    }

    return false;
}

/**
 * Validate all employees in a department for overtime assignment.
 * Returns ['eligible' => [...], 'ineligible' => [...], 'errors' => [...]]
 */
function validate_department_assignment(mysqli $conn, int $department_id, string $ot_date, string $start_time, string $end_time): array {
    $eligible = [];
    $ineligible = [];
    $global_errors = [];

    // Detect OT type
    $ot_type = detect_overtime_type($conn, $ot_date);

    // Validate time rules
    $time_validation = validate_overtime_time_rules($ot_type, $start_time, $end_time);
    if (!$time_validation['valid']) {
        $global_errors = $time_validation['errors'];
        return ['eligible' => $eligible, 'ineligible' => $ineligible, 'errors' => $global_errors];
    }

    // Get all active employees in department
    $stmt = $conn->prepare("SELECT id, name, employee_code FROM employee WHERE department_id = ? AND status = 'active' ORDER BY name");
    $stmt->bind_param('i', $department_id);
    $stmt->execute();
    $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($employees)) {
        $global_errors[] = 'No active employees found in this department.';
        return ['eligible' => $eligible, 'ineligible' => $ineligible, 'errors' => $global_errors];
    }

    $start_ts = strtotime($start_time);
    $end_ts = strtotime($end_time);
    $total_hours = round(($end_ts - $start_ts) / 3600, 2);

    foreach ($employees as $emp) {
        $eid = (int)$emp['id'];
        $validation = validate_employee_for_overtime($conn, $eid, $ot_date, false);

        // Check monthly limit
        $monthly = check_monthly_ot_limit($conn, $eid, $ot_date, $total_hours);
        if (!$monthly['within_limit']) {
            $validation['valid'] = false;
            $validation['errors'][] = $monthly['error'];
        }

        // Check duplicate
        if (check_duplicate_assignment($conn, $eid, $ot_date, $start_time, $end_time)) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Duplicate overtime assignment exists for this employee.';
        }

        $entry = [
            'id' => $eid,
            'name' => $emp['name'],
            'employee_code' => $emp['employee_code'],
            'eligible' => $validation['valid'],
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'] ?? [],
        ];

        if ($validation['valid']) {
            $eligible[] = $entry;
        } else {
            $ineligible[] = $entry;
        }
    }

    return ['eligible' => $eligible, 'ineligible' => $ineligible, 'errors' => $global_errors];
}

/**
 * Create overtime assignment and related records.
 * Returns assignment ID on success, null on failure.
 */
function create_overtime_assignment(mysqli $conn, array $data): ?int {
    set_mmt_timezone();

    $assignment_code = generate_assignment_code($conn);
    $assignment_type = $data['assignment_type'];
    $department_id = $data['department_id'] ?? null;
    $employee_id = $data['employee_id'] ?? null;
    $assigned_by = $data['assigned_by'];
    $assigned_by_name = $data['assigned_by_name'] ?? '';
    $assigned_by_position = $data['assigned_by_position'] ?? '';
    $ot_date = $data['ot_date'];
    $start_time = $data['start_time'];
    $end_time = $data['end_time'];
    $reason = $data['reason'] ?? '';
    $ot_type = detect_overtime_type($conn, $ot_date);

    $start_ts = strtotime($start_time);
    $end_ts = strtotime($end_time);
    $total_hours = round(($end_ts - $start_ts) / 3600, 2);

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Insert assignment header
        $stmt = $conn->prepare(
            "INSERT INTO overtime_assignments
             (assignment_code, assignment_type, department_id, employee_id, assigned_by, assigned_by_name, assigned_by_position,
              ot_date, start_time, end_time, total_hours, reason, ot_type, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Assigned')"
        );
        $stmt->bind_param('ssiiissssdsss',
            $assignment_code, $assignment_type, $department_id, $employee_id,
            $assigned_by, $assigned_by_name, $assigned_by_position,
            $ot_date, $start_time, $end_time, $total_hours, $reason, $ot_type
        );
        $stmt->execute();
        $assignment_id = $conn->insert_id;
        $stmt->close();

        if ($assignment_id <= 0) {
            throw new Exception('Failed to create assignment record.');
        }

        // Determine which employees to process
        if ($assignment_type === 'department' && $department_id) {
            $emp_stmt = $conn->prepare("SELECT id, basic_salary FROM employee WHERE department_id = ? AND status = 'active'");
            $emp_stmt->bind_param('i', $department_id);
            $emp_stmt->execute();
            $employees = $emp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $emp_stmt->close();
        } else {
            $emp_stmt = $conn->prepare("SELECT id, basic_salary FROM employee WHERE id = ?");
            $emp_stmt->bind_param('i', $employee_id);
            $emp_stmt->execute();
            $employees = $emp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $emp_stmt->close();
        }

        $ot_rate = get_overtime_rate_for_type($ot_type);
        $processed = 0;
        $skipped = 0;

        $oae_stmt = $conn->prepare(
            "INSERT INTO overtime_assignment_employees (assignment_id, employee_id, ot_rate, ot_pay, status, validation_notes)
             VALUES (?, ?, ?, ?, 'Assigned', ?)"
        );

        $or_stmt = $conn->prepare(
            "INSERT INTO overtime_records (assignment_id, employee_id, ot_date, start_time, end_time, total_hours, ot_type, ot_rate, ot_pay, hourly_salary, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved')"
        );

        foreach ($employees as $emp) {
            $eid = (int)$emp['id'];
            $basic = (float)($emp['basic_salary'] ?? 0);

            // Validate each employee (skip attendance check for department pre-assignment)
            $validation = validate_employee_for_overtime($conn, $eid, $ot_date, $assignment_type !== 'employee');
            $monthly = check_monthly_ot_limit($conn, $eid, $ot_date, $total_hours);
            $is_dup = check_duplicate_assignment($conn, $eid, $ot_date, $start_time, $end_time);

            $all_errors = array_merge($validation['errors']);
            if ($is_dup) $all_errors[] = 'Duplicate overtime assignment exists.';
            if (!$monthly['within_limit'] && !empty($monthly['error'])) $all_errors[] = $monthly['error'];

            if (!empty($all_errors)) {
                // Record as skipped
                $notes = implode('; ', $all_errors);
                $zero_pay = 0.0;
                $oae_stmt->bind_param('iidds', $assignment_id, $eid, $ot_rate, $zero_pay, $notes);
                $oae_stmt->execute();
                $skipped++;
                continue;
            }

            // Calculate OT pay
            $working_days = (int)get_overtime_setting($conn, 'payroll_working_days_per_month', '22');
            if ($working_days <= 0) $working_days = 22;
            $hourly_salary = $basic > 0 ? round($basic / ($working_days * 8), 2) : 0;
            $ot_pay = round($hourly_salary * $ot_rate * $total_hours, 2);

            // Insert assignment employee record
            $empty_notes = '';
            $oae_stmt->bind_param('iidds', $assignment_id, $eid, $ot_rate, $ot_pay, $empty_notes);
            $oae_stmt->execute();

            // Insert overtime record
            $or_stmt->bind_param('iisssdsddd',
                $assignment_id, $eid, $ot_date, $start_time, $end_time,
                $total_hours, $ot_type, $ot_rate, $ot_pay, $hourly_salary
            );
            $or_stmt->execute();

            $processed++;
        }

        $oae_stmt->close();
        $or_stmt->close();

        // Update assignment status based on results
        if ($processed === 0 && $skipped > 0) {
            $upd = $conn->prepare("UPDATE overtime_assignments SET status = 'Cancelled' WHERE id = ?");
            $upd->bind_param('i', $assignment_id);
            $upd->execute();
            $upd->close();
        }

        $conn->commit();
        return $assignment_id;

    } catch (Exception $e) {
        $conn->rollback();
        return null;
    }
}

/**
 * Get overtime assignment with employee details.
 */
function get_overtime_assignment_detail(mysqli $conn, int $assignment_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM overtime_assignments WHERE id = ?");
    $stmt->bind_param('i', $assignment_id);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$assignment) return null;

    // Get employees
    $emp_stmt = $conn->prepare(
        "SELECT oae.*, e.name as employee_name, e.employee_code, d.department_name, p.position_name
         FROM overtime_assignment_employees oae
         JOIN employee e ON oae.employee_id = e.id
         LEFT JOIN departments d ON e.department_id = d.id
         LEFT JOIN positions p ON e.position_id = p.id
         WHERE oae.assignment_id = ?
         ORDER BY e.name"
    );
    $emp_stmt->bind_param('i', $assignment_id);
    $emp_stmt->execute();
    $assignment['employees'] = $emp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $emp_stmt->close();

    // Department name
    if ($assignment['department_id']) {
        $dept_stmt = $conn->prepare("SELECT department_name FROM departments WHERE id = ?");
        $dept_stmt->bind_param('i', $assignment['department_id']);
        $dept_stmt->execute();
        $dept_row = $dept_stmt->get_result()->fetch_assoc();
        $dept_stmt->close();
        $assignment['department_name'] = $dept_row['department_name'] ?? '';
    }

    return $assignment;
}

/**
 * Get list of overtime assignments with filters.
 */
function get_overtime_assignments(mysqli $conn, array $filters = []): array {
    $where = ["1=1"];
    $params = [];
    $types = '';

    if (!empty($filters['from_date'])) {
        $where[] = "oa.ot_date >= ?";
        $params[] = $filters['from_date'];
        $types .= 's';
    }
    if (!empty($filters['to_date'])) {
        $where[] = "oa.ot_date <= ?";
        $params[] = $filters['to_date'];
        $types .= 's';
    }
    if (!empty($filters['status'])) {
        $where[] = "oa.status = ?";
        $params[] = $filters['status'];
        $types .= 's';
    }
    if (!empty($filters['assignment_type'])) {
        $where[] = "oa.assignment_type = ?";
        $params[] = $filters['assignment_type'];
        $types .= 's';
    }
    if (!empty($filters['department_id'])) {
        $where[] = "oa.department_id = ?";
        $params[] = (int)$filters['department_id'];
        $types .= 'i';
    }

    $sql = "SELECT oa.*,
            d.department_name,
            e.name as employee_name,
            (SELECT COUNT(*) FROM overtime_assignment_employees WHERE assignment_id = oa.id) as total_employees,
            (SELECT COUNT(*) FROM overtime_assignment_employees WHERE assignment_id = oa.id AND eligible = 1 OR (validation_notes IS NULL OR validation_notes = '')) as eligible_count
            FROM overtime_assignments oa
            LEFT JOIN departments d ON oa.department_id = d.id
            LEFT JOIN employee e ON oa.employee_id = e.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY oa.created_at DESC";

    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $result;
}

/**
 * Cancel an overtime assignment and its related records.
 */
function cancel_overtime_assignment(mysqli $conn, int $assignment_id): bool {
    $conn->begin_transaction();
    try {
        // Update assignment status
        $stmt = $conn->prepare("UPDATE overtime_assignments SET status = 'Cancelled' WHERE id = ?");
        $stmt->bind_param('i', $assignment_id);
        $stmt->execute();
        $stmt->close();

        // Update all employee records
        $stmt2 = $conn->prepare("UPDATE overtime_assignment_employees SET status = 'Cancelled' WHERE assignment_id = ?");
        $stmt2->bind_param('i', $assignment_id);
        $stmt2->execute();
        $stmt2->close();

        // Cancel overtime_records linked to this assignment
        $has_or = $conn->query("SHOW TABLES LIKE 'overtime_records'")->num_rows > 0;
        if ($has_or) {
            $stmt3 = $conn->prepare("UPDATE overtime_records SET status = 'Cancelled' WHERE assignment_id = ? AND payroll_id IS NULL");
            $stmt3->bind_param('i', $assignment_id);
            $stmt3->execute();
            $stmt3->close();
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

// ─── Checkout Reminder System ────────────────────────────────────

function needs_checkout_reminder(mysqli $conn, int $employee_id): ?string {
    set_mmt_timezone();
    $today = mmt_date();
    $current_time = mmt_time();

    if (!is_working_day($conn, $today)) return null;

    if (has_approved_leave_on_date($conn, $employee_id, $today)) return null;

    $att = has_checked_in_today($conn, $employee_id, $today);
    if (!$att || !$att['check_in']) return null;
    if ($att['check_out'] !== null) return null;

    $work_end = strtotime(get_work_end_time());
    $now = strtotime($current_time);

    if ($now < $work_end) return null;

    $minutes_past = ($now - $work_end) / 60;

    if ($minutes_past >= 120) return 'final';
    if ($minutes_past >= 60) return 'second';
    return 'first';
}

function get_checkout_reminder_message(string $level): string {
    set_mmt_timezone();
    $now = mmt_time('h:i A');
    return match($level) {
        'first'  => "Don't forget to check out for today! Work hours ended at 5:00 PM.",
        'second' => "Reminder: You haven't checked out yet. It's been over 1 hour past work hours.",
        'final'  => "Important: You are still not checked out. Please check out immediately to avoid AWOL status.",
        default  => "Please check out for today."
    };
}

function get_checkout_reminder_urgency(string $level): string {
    return match($level) {
        'first'  => 'info',
        'second' => 'warning',
        'final'  => 'danger',
        default  => 'info'
    };
}

function log_checkout_reminder(mysqli $conn, int $employee_id, string $level): int {
    $att = has_checked_in_today($conn, $employee_id);
    $att_id = $att ? $att['id'] : null;

    $stmt = $conn->prepare("INSERT INTO checkout_reminders (employee_id, attendance_id, reminder_type, reminder_level) VALUES (?, ?, 'checkout', ?)");
    $stmt->bind_param('iis', $employee_id, $att_id, $level);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function get_latest_pending_reminder(mysqli $conn, int $employee_id): ?array {
    set_mmt_timezone();
    $today = mmt_date();

    $stmt = $conn->prepare("SELECT id, reminder_level, notification_status, sent_at FROM checkout_reminders WHERE employee_id = ? AND DATE(sent_at) = ? ORDER BY sent_at DESC LIMIT 1");
    $stmt->bind_param('is', $employee_id, $today);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

function dismiss_checkout_reminder(mysqli $conn, int $reminder_id): void {
    $stmt = $conn->prepare("UPDATE checkout_reminders SET notification_status = 'dismissed', dismissed_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $reminder_id);
    $stmt->execute();
    $stmt->close();
}

function get_unread_checkout_reminder_count(mysqli $conn, int $employee_id): int {
    set_mmt_timezone();
    $today = mmt_date();

    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM checkout_reminders WHERE employee_id = ? AND DATE(sent_at) = ? AND notification_status = 'sent'");
    $stmt->bind_param('is', $employee_id, $today);
    $stmt->execute();
    $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $cnt;
}

function should_send_new_reminder(mysqli $conn, int $employee_id, string $level): bool {
    set_mmt_timezone();
    $today = mmt_date();

    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM checkout_reminders WHERE employee_id = ? AND DATE(sent_at) = ? AND reminder_level = ?");
    $stmt->bind_param('iss', $employee_id, $today, $level);
    $stmt->execute();
    $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $cnt === 0;
}

// ─── New Payroll Module (payroll table) ──────────────────────

/**
 * Check if the new `payroll` table exists.
 */
function payroll_table_exists(mysqli $conn): bool {
    $res = $conn->query("SHOW TABLES LIKE 'payroll'");
    return $res && $res->num_rows > 0;
}

/**
 * Calculate payroll for a single employee using the unified payroll formula.
 * Salary Formula:
 *   Daily Salary = Basic Salary / Working Days
 *   Attendance Salary = (Present × Daily) + (Half Days × Daily × 0.5) + (Paid Leave × Daily)
 *   Unpaid Leave Deduction = Unpaid Leave Days × Daily Salary
 *   Gross Salary = Attendance Salary + OT Pay + Bonus
 *   Net Salary = Gross Salary - Unpaid Leave Deduction - Total Deduction
 */
function calculate_new_payroll(mysqli $conn, int $employee_id, int $month, int $year): ?array {
    $month_start = sprintf('%04d-%02d-01', $year, $month);
    $month_end = date('Y-m-t', strtotime($month_start));
    $working_days = get_working_days_in_month($year, $month);

    $emp_stmt = $conn->prepare("SELECT basic_salary FROM employee WHERE id = ? AND status = 'active'");
    $emp_stmt->bind_param('i', $employee_id);
    $emp_stmt->execute();
    $emp = $emp_stmt->get_result()->fetch_assoc();
    $emp_stmt->close();
    if (!$emp) return null;

    $basic_salary = (float)($emp['basic_salary'] ?? 0);
    $daily_rate = calculate_daily_salary($basic_salary, $working_days);
    $hourly_rate = calculate_hourly_rate($basic_salary, $working_days);

    // --- Attendance Summary ---
    $att = get_monthly_attendance_summary($conn, $employee_id, $month_start, $month_end);
    $present_days = (int)($att['present_days'] ?? 0);
    $half_days = (int)($att['half_days'] ?? 0);
    $late_days = (int)($att['late_days'] ?? 0);
    $absent_days = (int)($att['absent_days'] ?? 0);
    $paid_leave_days = (int)($att['paid_leave_days'] ?? 0);
    $unpaid_leave_days = (int)($att['unpaid_leave_days'] ?? 0);
    $leave_days = $paid_leave_days + $unpaid_leave_days;

    // --- Attendance Salary (per user formula) ---
    $attendance_salary = ($present_days * $daily_rate)
                       + ($half_days * $daily_rate * 0.5)
                       + ($paid_leave_days * $daily_rate);

    // --- Unpaid Leave Deduction ---
    $unpaid_leave_deduction = $unpaid_leave_days * $daily_rate;

    // --- Late Penalty ---
    // Check for late_deduction_percent (percentage of daily rate per late day)
    $late_percent = (float)get_company_policy($conn, 'late_deduction_percent', 0);
    $late_penalty_rate = (float)get_company_policy($conn, 'late_penalty_per_occurrence', 0);

    if ($late_percent > 0) {
        // Late deduction as percentage of daily rate per occurrence
        $late_deduction = round($daily_rate * ($late_percent / 100) * $late_days, 2);
    } elseif ($late_penalty_rate > 0) {
        // Fixed penalty per late occurrence
        $late_deduction = $late_penalty_rate * $late_days;
    } else {
        $late_deduction = 0;
    }

    // --- Leave Deduction (Unpaid Leave) ---
    $leave_deduction = $unpaid_leave_deduction;

    // --- Absent Deduction ---
    $absent_deduction = $daily_rate * $absent_days;

    // --- Half Day Deduction ---
    $half_day_deduction = $daily_rate * 0.5 * $half_days;

    // --- Other Deductions (from deductions table) ---
    $ded_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM deductions WHERE employee_id = ? AND deduction_date BETWEEN ? AND ?");
    $ded_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $ded_stmt->execute();
    $other_deduction = (float)$ded_stmt->get_result()->fetch_assoc()['total'];
    $ded_stmt->close();

    // --- Total Deductions ---
    $total_deduction = $unpaid_leave_deduction + $late_deduction + $absent_deduction + $half_day_deduction + $other_deduction;

    // --- Overtime ---
    $overtime_hours = (float)($att['total_hours_worked'] ?? 0);
    $overtime_amount = calculate_overtime_amount_for_payroll($conn, $employee_id, $month_start, $month_end, $hourly_rate);

    // OT breakdown by type
    $ot_breakdown = ['working_day' => 0.0, 'weekend' => 0.0, 'holiday' => 0.0];
    $has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;
    if ($has_ot_type) {
        $ot_stmt = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as hrs, ot_type FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved' GROUP BY ot_type");
        $ot_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
        $ot_stmt->execute();
        $ot_rows = $ot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ot_stmt->close();
        foreach ($ot_rows as $r) {
            $key = $r['ot_type'] ?? 'working_day';
            if (isset($ot_breakdown[$key])) $ot_breakdown[$key] = (float)$r['hrs'];
            else $ot_breakdown['working_day'] += (float)$r['hrs'];
        }
    }

    // --- Bonus ---
    $bonus_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM bonuses WHERE employee_id = ? AND bonus_date BETWEEN ? AND ?");
    $bonus_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
    $bonus_stmt->execute();
    $bonus = (float)$bonus_stmt->get_result()->fetch_assoc()['total'];
    $bonus_stmt->close();

    // --- Allowance ---
    $allow_stmt = $conn->prepare("SELECT COALESCE(epi.allowance, 0) as allowance FROM employee_personal_info epi WHERE epi.employee_id = ?");
    $allow_stmt->bind_param('i', $employee_id);
    $allow_stmt->execute();
    $allowance = (float)($allow_stmt->get_result()->fetch_assoc()['allowance'] ?? 0);
    $allow_stmt->close();

    // --- Gross Salary = Attendance Salary + OT Pay + Bonus ---
    $gross_salary = $attendance_salary + $overtime_amount + $bonus;

    // --- Net Salary = Gross - Total Deduction ---
    $net_salary = $gross_salary - $total_deduction;

    return [
        'employee_id'        => $employee_id,
        'pay_month'          => $month,
        'pay_year'           => $year,
        'basic_salary'       => $basic_salary,
        'attendance_salary'  => $attendance_salary,
        'working_days'       => $working_days,
        'present_days'       => $present_days,
        'half_days'          => $half_days,
        'paid_leave_days'    => $paid_leave_days,
        'unpaid_leave_days'  => $unpaid_leave_days,
        'leave_days'         => $leave_days,
        'late_days'          => $late_days,
        'absent_days'        => $absent_days,
        'overtime_hours'     => $overtime_hours,
        'ot_working_day_hours' => $ot_breakdown['working_day'],
        'ot_weekend_hours'   => $ot_breakdown['weekend'],
        'ot_holiday_hours'   => $ot_breakdown['holiday'],
        'overtime_amount'    => $overtime_amount,
        'unpaid_leave_deduction' => $unpaid_leave_deduction,
        'leave_deduction'    => $unpaid_leave_deduction,
        'half_day_deduction' => $half_day_deduction,
        'late_deduction'     => $late_deduction,
        'absent_deduction'   => $absent_deduction,
        'bonus'              => $bonus,
        'allowance'          => $allowance,
        'other_deduction'    => $other_deduction,
        'total_deduction'    => $total_deduction,
        'gross_salary'       => $gross_salary,
        'net_salary'         => $net_salary,
    ];
}

/**
 * Generate a unique payroll code like PAY-2026-07-001
 */
function generate_payroll_code(mysqli $conn, int $year, int $month): string {
    $prefix = sprintf('PAY-%04d-%02d-', $year, $month);
    $stmt = $conn->prepare("SELECT payroll_code FROM payroll WHERE payroll_code LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && $row['payroll_code']) {
        $last_num = (int)substr($row['payroll_code'], -3);
        $new_num = $last_num + 1;
    } else {
        $new_num = 1;
    }
    return $prefix . str_pad($new_num, 3, '0', STR_PAD_LEFT);
}

/**
 * Generate (insert/update) a payroll record in the `payroll` table.
 * Returns the payroll ID on success, null on failure.
 */
function generate_new_payroll(mysqli $conn, int $employee_id, int $month, int $year): ?int {
    $data = calculate_new_payroll($conn, $employee_id, $month, $year);
    if (!$data) return null;

    $payroll_code = generate_payroll_code($conn, $year, $month);

    $upsert = $conn->prepare("INSERT INTO payroll (
        payroll_code, employee_id, pay_month, pay_year, basic_salary, attendance_salary,
        working_days, present_days, half_days, paid_leave_days, unpaid_leave_days,
        leave_days, late_days, absent_days,
        overtime_hours, ot_working_day_hours, ot_weekend_hours, ot_holiday_hours, overtime_amount,
        leave_deduction, unpaid_leave_deduction, half_day_deduction, late_deduction, absent_deduction,
        bonus, allowance, other_deduction, total_deduction,
        gross_salary, net_salary, payment_status, generated_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', CURDATE())
    ON DUPLICATE KEY UPDATE
        payroll_code = VALUES(payroll_code),
        basic_salary = VALUES(basic_salary),
        attendance_salary = VALUES(attendance_salary),
        working_days = VALUES(working_days),
        present_days = VALUES(present_days),
        half_days = VALUES(half_days),
        paid_leave_days = VALUES(paid_leave_days),
        unpaid_leave_days = VALUES(unpaid_leave_days),
        leave_days = VALUES(leave_days),
        late_days = VALUES(late_days),
        absent_days = VALUES(absent_days),
        overtime_hours = VALUES(overtime_hours),
        ot_working_day_hours = VALUES(ot_working_day_hours),
        ot_weekend_hours = VALUES(ot_weekend_hours),
        ot_holiday_hours = VALUES(ot_holiday_hours),
        overtime_amount = VALUES(overtime_amount),
        leave_deduction = VALUES(leave_deduction),
        unpaid_leave_deduction = VALUES(unpaid_leave_deduction),
        half_day_deduction = VALUES(half_day_deduction),
        late_deduction = VALUES(late_deduction),
        absent_deduction = VALUES(absent_deduction),
        bonus = VALUES(bonus),
        allowance = VALUES(allowance),
        other_deduction = VALUES(other_deduction),
        total_deduction = VALUES(total_deduction),
        gross_salary = VALUES(gross_salary),
        net_salary = VALUES(net_salary),
        payment_status = 'Pending',
        generated_date = CURDATE(),
        updated_at = CURRENT_TIMESTAMP");

    $upsert->bind_param('siiiddiiiiiiiidddddddddddddddd',
        $payroll_code, $data['employee_id'], $data['pay_month'], $data['pay_year'], $data['basic_salary'], $data['attendance_salary'],
        $data['working_days'], $data['present_days'], $data['half_days'], $data['paid_leave_days'], $data['unpaid_leave_days'],
        $data['leave_days'], $data['late_days'], $data['absent_days'],
        $data['overtime_hours'], $data['ot_working_day_hours'], $data['ot_weekend_hours'], $data['ot_holiday_hours'], $data['overtime_amount'],
        $data['leave_deduction'], $data['unpaid_leave_deduction'], $data['half_day_deduction'], $data['late_deduction'], $data['absent_deduction'],
        $data['bonus'], $data['allowance'], $data['other_deduction'], $data['total_deduction'],
        $data['gross_salary'], $data['net_salary']
    );
    $upsert->execute();
    $payroll_id = $conn->insert_id;
    $upsert->close();

    if ($payroll_id <= 0) {
        $stmt = $conn->prepare("SELECT id FROM payroll WHERE employee_id = ? AND pay_month = ? AND pay_year = ?");
        $stmt->bind_param('iii', $data['employee_id'], $data['pay_month'], $data['pay_year']);
        $stmt->execute();
        $payroll_id = (int)$stmt->get_result()->fetch_assoc()['id'];
        $stmt->close();
    }

    return $payroll_id > 0 ? $payroll_id : null;
}

/**
 * Batch-generate payroll for all active employees for a given month/year.
 * Returns [inserted_count, total_working_days].
 */
function generate_batch_new_payroll(mysqli $conn, int $month, int $year): array {
    $emp_query = $conn->query("SELECT id FROM employee WHERE status = 'active'");
    $inserted = 0;
    $month_name = date('F', mktime(0, 0, 0, $month, 1));
    
    while ($emp = $emp_query->fetch_assoc()) {
        $pid = generate_new_payroll($conn, (int)$emp['id'], $month, $year);
        if ($pid) {
            $inserted++;
            // Create notification for employee
            $payroll = get_new_payroll_detail($conn, $pid);
            if ($payroll) {
                create_payroll_notification($conn, (int)$emp['id'], 'generated', $month_name, $year, $payroll['net_salary']);
            }
        }
    }
    $emp_query->close();
    return [$inserted, get_working_days_in_month($year, $month)];
}

/**
 * Get a single payroll record from the new table, with employee info.
 */
function get_new_payroll_detail(mysqli $conn, int $payroll_id): ?array {
    $stmt = $conn->prepare("
        SELECT p.*, e.name, e.employee_code, e.email, e.basic_salary as emp_basic_salary,
               d.department_name, pos.position_name
        FROM payroll p
        JOIN employee e ON p.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions pos ON e.position_id = pos.id
        WHERE p.id = ?
    ");
    $stmt->bind_param('i', $payroll_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

/**
 * Update payment status for a payroll record.
 */
function update_new_payroll_status(mysqli $conn, int $payroll_id, string $status, ?string $remarks = null): bool {
    $set_parts = ['payment_status = ?'];
    $bind_types = 's';
    $params = [$status];

    if ($status === 'Paid') {
        $set_parts[] = 'paid_date = CURDATE()';
    }

    if ($remarks !== null) {
        $set_parts[] = 'remarks = ?';
        $bind_types .= 's';
        $params[] = $remarks;
    }

    $params[] = $payroll_id;
    $bind_types .= 'i';
    $set_sql = implode(', ', $set_parts);
    $stmt = $conn->prepare("UPDATE payroll SET $set_sql WHERE id = ?");
    $stmt->bind_param($bind_types, ...$params);
    $result = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    // Create notification for employee when payroll is paid
    if ($result && $status === 'Paid' && $affected > 0) {
        $payroll = get_new_payroll_detail($conn, $payroll_id);
        if ($payroll) {
            $month_name = date('F', mktime(0, 0, 0, $payroll['pay_month'], 1));
            create_payroll_notification($conn, $payroll['employee_id'], 'paid', $month_name, $payroll['pay_year'], $payroll['net_salary']);
        }
    }

    return $result;
}

/**
 * Get payment status badge CSS classes for the payroll table.
 */
function get_new_payroll_status_badge(string $status): string {
    return match ($status) {
        'Draft'     => 'bg-zinc-500/15 text-zinc-400 border border-zinc-500/20',
        'Generated' => 'bg-blue-500/15 text-blue-400 border border-blue-500/20',
        'Reviewed'  => 'bg-cyan-500/15 text-cyan-400 border border-cyan-500/20',
        'Approved'  => 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/20',
        'Pending'   => 'bg-amber-500/15 text-amber-400 border border-amber-500/20',
        'Paid'      => 'bg-purple-500/15 text-purple-400 border border-purple-500/20',
        'Cancelled' => 'bg-rose-500/15 text-rose-400 border border-rose-500/20',
        default     => 'bg-zinc-500/15 text-zinc-400 border border-zinc-500/20',
    };
}

/**
 * Get payment status icon for the payroll table.
 */
function get_new_payroll_status_icon(string $status): string {
    return match ($status) {
        'Draft'     => 'fa-pen-to-square',
        'Generated' => 'fa-calculator',
        'Reviewed'  => 'fa-magnifying-glass',
        'Approved'  => 'fa-check-circle',
        'Pending'   => 'fa-clock',
        'Paid'      => 'fa-sack-dollar',
        'Cancelled' => 'fa-ban',
        default     => 'fa-circle',
    };
}
