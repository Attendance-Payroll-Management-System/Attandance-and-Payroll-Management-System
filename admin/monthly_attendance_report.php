<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

// ─── Security: Validate Admin/HR Role ────────────────────────────
$admin_id = $_SESSION['admin_id'] ?? null;
$admin_role = null;
if ($admin_id) {
    $role_stmt = $conn->prepare("SELECT role FROM employee WHERE id = ?");
    $role_stmt->bind_param('i', $admin_id);
    $role_stmt->execute();
    $admin_role = $role_stmt->get_result()->fetch_assoc()['role'] ?? '';
    $role_stmt->close();
}
if ($admin_role !== 'officer' && $admin_role !== 'admin') {
    $_SESSION['message'] = "Access denied. Only Admin and HR can view this report.";
    $_SESSION['message_type'] = 'error';
    header('Location: dashboard.php');
    exit;
}

// ─── Validate & Sanitize GET Parameters ──────────────────────────
$view_mode = isset($_GET['view']) && in_array($_GET['view'], ['daily', 'weekly', 'monthly']) ? $_GET['view'] : 'monthly';
$selected_month = isset($_GET['month']) && in_array((int)$_GET['month'], range(1, 12)) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) && (int)$_GET['year'] >= 2020 && (int)$_GET['year'] <= 2100 ? (int)$_GET['year'] : (int)date('Y');
$selected_dept = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$search_name = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$per_page = 15;

$valid_sort_cols = ['name', 'department_name', 'present_days', 'late_days', 'absent_days', 'ot_hours', 'ot_pay'];
$sort_col = isset($_GET['sort']) && in_array($_GET['sort'], $valid_sort_cols) ? $_GET['sort'] : 'name';
$sort_dir = isset($_GET['dir']) && strtoupper($_GET['dir']) === 'DESC' ? 'DESC' : 'ASC';

$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

$today = date('Y-m-d');

// ─── Helper Functions ────────────────────────────────────────────
function hours_to_time($decimal): string {
    if ($decimal <= 0) return '0h 0m';
    $h = floor($decimal);
    $m = round(($decimal - $h) * 60);
    return $h . 'h ' . $m . 'm';
}

function build_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    unset($params['export']);
    return '?' . http_build_query($params);
}

function ot_type_label(string $type): string {
    return match($type) {
        'working_day', 'weekday' => 'Working Day OT',
        'weekend' => 'Weekend OT',
        'holiday' => 'Holiday OT',
        default => ucfirst(str_replace('_', ' ', $type)) . ' OT',
    };
}

function ot_type_color(string $type): array {
    return match($type) {
        'working_day', 'weekday' => ['bg' => 'bg-blue-500/10', 'border' => 'border-blue-500/20', 'text' => 'text-blue-400', 'dot' => 'bg-blue-400', 'light_bg' => 'bg-blue-50 dark:bg-blue-500/10', 'light_text' => 'text-blue-600 dark:text-blue-400'],
        'weekend' => ['bg' => 'bg-amber-500/10', 'border' => 'border-amber-500/20', 'text' => 'text-amber-400', 'dot' => 'bg-amber-400', 'light_bg' => 'bg-amber-50 dark:bg-amber-500/10', 'light_text' => 'text-amber-600 dark:text-amber-400'],
        'holiday' => ['bg' => 'bg-rose-500/10', 'border' => 'border-rose-500/20', 'text' => 'text-rose-400', 'dot' => 'bg-rose-400', 'light_bg' => 'bg-rose-50 dark:bg-rose-500/10', 'light_text' => 'text-rose-600 dark:text-rose-400'],
        default => ['bg' => 'bg-zinc-500/10', 'border' => 'border-zinc-500/20', 'text' => 'text-zinc-400', 'dot' => 'bg-zinc-400', 'light_bg' => 'bg-zinc-50 dark:bg-zinc-500/10', 'light_text' => 'text-zinc-600 dark:text-zinc-400'],
    };
}

// ─── Handle Export ───────────────────────────────────────────────
$export_mode = $_GET['export'] ?? '';
if (in_array($export_mode, ['excel', 'pdf']) && in_array($sort_col, $valid_sort_cols)) {
    $export_data = fetch_report_data($conn, $month_start, $month_end, $selected_dept, $search_name, $sort_col, $sort_dir, 0, 99999);
    $ot_details = [];
    if (!empty($export_data)) {
        $emp_ids = array_column($export_data, 'id');
        $placeholders = implode(',', array_fill(0, count($emp_ids), '?'));
        $ot_stmt = $conn->prepare("SELECT employee_id, ot_date, CAST(ot_type AS CHAR) as ot_type, total_hours, status, ot_pay FROM overtime_requests WHERE employee_id IN ($placeholders) AND ot_date BETWEEN ? AND ? AND status = 'Approved'
            UNION ALL
            SELECT employee_id, ot_date, CAST(ot_type AS CHAR) as ot_type, total_hours, status, ot_pay FROM overtime_records WHERE employee_id IN ($placeholders) AND ot_date BETWEEN ? AND ? AND status = 'Approved'
            ORDER BY employee_id, ot_date");
        $all_types = str_repeat('i', count($emp_ids)) . 'ss' . str_repeat('i', count($emp_ids)) . 'ss';
        $all_params = array_merge($emp_ids, [$month_start, $month_end, $emp_ids, $month_start, $month_end]);
        $ot_stmt->bind_param($all_types, ...$all_params);
        $ot_stmt->execute();
        $all_ot = $ot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ot_stmt->close();
        foreach ($all_ot as $row) {
            $ot_details[(int)$row['employee_id']][] = $row;
        }
    }
    if ($export_mode === 'excel') {
        export_report_excel($export_data, $ot_details, $selected_month, $selected_year);
    } else {
        export_report_pdf($export_data, $ot_details, $selected_month, $selected_year);
    }
}

// ─── AJAX OT Details (must run before data fetching to avoid unnecessary queries) ──
if (isset($_GET['ajax_ot']) && $_GET['ajax_ot'] === '1') {
    $emp_id = isset($_GET['emp_id']) ? (int)$_GET['emp_id'] : 0;
    header('Content-Type: application/json');
    if ($emp_id <= 0) { echo json_encode([]); exit; }
    try {
        $ot_stmt = $conn->prepare("SELECT ot_date, CAST(ot_type AS CHAR) as ot_type, total_hours, status, ot_pay, start_time, end_time FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
            UNION ALL
            SELECT ot_date, CAST(ot_type AS CHAR) as ot_type, total_hours, status, ot_pay, start_time, end_time FROM overtime_records WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
            ORDER BY ot_date");
        $ot_stmt->bind_param('isssss', $emp_id, $month_start, $month_end, $emp_id, $month_start, $month_end);
        $ot_stmt->execute();
        $ot_rows = $ot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ot_stmt->close();
        echo json_encode($ot_rows);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── Fetch Data for View Modes ──────────────────────────────────
$records = [];
$total_records = 0;
$total_pages = 1;
$summary = ['total_employees' => 0, 'total_present' => 0, 'total_late' => 0, 'total_absent' => 0, 'total_leave' => 0, 'total_ot_hours' => 0, 'total_ot_pay' => 0];

if ($view_mode === 'monthly') {
    $total_records = count_report_records($conn, $month_start, $month_end, $selected_dept, $search_name);
    $total_pages = max(1, ceil($total_records / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    $records = fetch_report_data($conn, $month_start, $month_end, $selected_dept, $search_name, $sort_col, $sort_dir, $per_page, $offset);
    $summary = fetch_summary($conn, $month_start, $month_end, $selected_dept, $search_name);
}

// Daily view: all employees for selected date
$daily_records = [];
if ($view_mode === 'daily') {
    $daily_records = fetch_daily_data($conn, $selected_date, $selected_dept, $search_name);
}

// Weekly view: week range
$week_start = date('Y-m-d', strtotime($selected_date . ' -' . ((int)date('N', strtotime($selected_date)) - 1) . ' days'));
$week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
$weekly_summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'ot_hours' => 0, 'ot_pay' => 0];
$weekly_daily_data = [];
if ($view_mode === 'weekly') {
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($week_start . " +$i days"));
        $daily = fetch_daily_data($conn, $d, $selected_dept, $search_name);
        $weekly_daily_data[$d] = $daily;
        foreach ($daily as $dr) {
            if (in_array($dr['status'], ['present', 'late', 'half_day'])) $weekly_summary['present']++;
            if ($dr['status'] === 'late') $weekly_summary['late']++;
            if (in_array($dr['status'], ['absent', 'awol', 'full_absent', 'half_absent'])) $weekly_summary['absent']++;
            if (in_array($dr['status'], ['leave', 'paid_leave', 'unpaid_leave'])) $weekly_summary['leave']++;
            $weekly_summary['ot_hours'] += (float)$dr['ot_hours'];
            $weekly_summary['ot_pay'] += (float)$dr['ot_pay'];
        }
    }
}

function fetch_daily_data(mysqli $conn, string $date, int $dept, string $search): array {
    $sql = "SELECT e.id, e.name, COALESCE(d.department_name, 'N/A') as department_name,
            COALESCE(p.position_name, 'N/A') as position_name,
            a.check_in, a.check_out, a.status, a.total_working_hours, a.is_late,
            COALESCE(ot.ot_hours, 0) as ot_hours, COALESCE(ot.ot_pay, 0) as ot_pay,
            COALESCE(ot.ot_type, '') as ot_type, COALESCE(ot.ot_start, '') as ot_start, COALESCE(ot.ot_end, '') as ot_end
        FROM employee e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions p ON e.position_id = p.id
        LEFT JOIN attendance a ON e.id = a.employee_id AND a.attendance_date = ?
        LEFT JOIN (
            SELECT employee_id, GROUP_CONCAT(ot_type SEPARATOR ',') as ot_type,
                   SUM(total_hours) as ot_hours, SUM(ot_pay) as ot_pay,
                   MIN(start_time) as ot_start, MAX(end_time) as ot_end
            FROM (
                SELECT employee_id, CAST(ot_type AS CHAR) as ot_type, total_hours, ot_pay, start_time, end_time FROM overtime_requests WHERE ot_date = ? AND status = 'Approved'
                UNION ALL
                SELECT employee_id, CAST(ot_type AS CHAR) as ot_type, total_hours, ot_pay, start_time, end_time FROM overtime_records WHERE ot_date = ? AND status = 'Approved'
            ) combined GROUP BY employee_id
        ) ot ON e.id = ot.employee_id
        WHERE e.status = 'active'";
    $types = 'sss';
    $params = [$date, $date, $date];
    if ($dept > 0) { $sql .= " AND e.department_id = ?"; $types .= 'i'; $params[] = $dept; }
    if ($search !== '') { $sql .= " AND e.name LIKE ?"; $types .= 's'; $params[] = "%$search%"; }
    $sql .= " ORDER BY e.name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $data;
}

function count_report_records(mysqli $conn, string $start, string $end, int $dept, string $search): int {
    $sql = "SELECT COUNT(DISTINCT e.id) as cnt FROM employee e WHERE e.status = 'active'";
    $types = '';
    $params = [];
    if ($dept > 0) { $sql .= " AND e.department_id = ?"; $types .= 'i'; $params[] = $dept; }
    if ($search !== '') { $sql .= " AND e.name LIKE ?"; $types .= 's'; $params[] = "%$search%"; }
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $cnt;
}

function fetch_report_data(mysqli $conn, string $start, string $end, int $dept, string $search, string $sort, string $dir, int $limit, int $offset): array {
    $sort_map = [
        'name' => 'e.name', 'department_name' => 'd.department_name',
        'present_days' => 'att.present_days', 'late_days' => 'att.late_days',
        'absent_days' => 'att.absent_days',
        'ot_hours' => 'COALESCE(ot.ot_hours, 0)',
        'ot_pay' => 'COALESCE(ot.ot_pay, 0)'
    ];
    $order_clause = $sort_map[$sort] ?? 'e.name';
    $dir_sql = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

    $sql = "SELECT e.id, e.name, COALESCE(d.department_name, 'N/A') as department_name,
            COALESCE(p.position_name, 'N/A') as position_name,
            COALESCE(att.present_days, 0) as present_days,
            COALESCE(att.late_days, 0) as late_days,
            COALESCE(att.half_days, 0) as half_days,
            COALESCE(att.absent_days, 0) as absent_days,
            COALESCE(att.awol_days, 0) as awol_days,
            COALESCE(att.paid_leave_days, 0) as paid_leave_days,
            COALESCE(att.unpaid_leave_days, 0) as unpaid_leave_days,
            COALESCE(att.holiday_days, 0) as holiday_days,
            COALESCE(att.weekend_days, 0) as weekend_days,
            COALESCE(att.total_hours, 0) as total_hours,
            COALESCE(ot.ot_hours, 0) as ot_hours,
            COALESCE(ot.ot_pay, 0) as ot_pay
        FROM employee e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions p ON e.position_id = p.id
        LEFT JOIN (
            SELECT employee_id,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days,
                SUM(CASE WHEN status IN ('awol') THEN 1 ELSE 0 END) as awol_days,
                SUM(CASE WHEN status IN ('absent','full_absent') THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status IN ('leave','paid_leave') THEN 1 ELSE 0 END) as paid_leave_days,
                SUM(CASE WHEN status = 'unpaid_leave' THEN 1 ELSE 0 END) as unpaid_leave_days,
                SUM(CASE WHEN status = 'public_holiday' THEN 1 ELSE 0 END) as holiday_days,
                SUM(CASE WHEN status = 'weekend' THEN 1 ELSE 0 END) as weekend_days,
                COALESCE(SUM(total_working_hours), 0) as total_hours
            FROM attendance WHERE attendance_date BETWEEN ? AND ? GROUP BY employee_id
        ) att ON e.id = att.employee_id
        LEFT JOIN (
            SELECT employee_id, SUM(ot_hours) as ot_hours, SUM(ot_pay) as ot_pay FROM (
                SELECT employee_id, total_hours as ot_hours, ot_pay
                FROM overtime_requests WHERE ot_date BETWEEN ? AND ? AND status = 'Approved'
                UNION ALL
                SELECT employee_id, total_hours as ot_hours, ot_pay
                FROM overtime_records WHERE ot_date BETWEEN ? AND ? AND status = 'Approved'
            ) combined GROUP BY employee_id
        ) ot ON e.id = ot.employee_id
        WHERE e.status = 'active'";

    $types = 'ssssss';
    $params = [$start, $end, $start, $end, $start, $end];

    if ($dept > 0) { $sql .= " AND e.department_id = ?"; $types .= 'i'; $params[] = $dept; }
    if ($search !== '') { $sql .= " AND e.name LIKE ?"; $types .= 's'; $params[] = "%$search%"; }

    $sql .= " ORDER BY $order_clause $dir_sql";
    if ($limit > 0) { $sql .= " LIMIT ? OFFSET ?"; $types .= 'ii'; $params[] = $limit; $params[] = $offset; }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $data;
}

function fetch_summary(mysqli $conn, string $start, string $end, int $dept, string $search): array {
    $sql = "SELECT COUNT(DISTINCT e.id) as total_employees,
            COALESCE(att_agg.total_present, 0) as total_present,
            COALESCE(att_agg.total_late, 0) as total_late,
            COALESCE(att_agg.total_absent, 0) as total_absent,
            COALESCE(att_agg.total_leave, 0) as total_leave,
            COALESCE(ot_agg.total_ot_hours, 0) as total_ot_hours,
            COALESCE(ot_agg.total_ot_pay, 0) as total_ot_pay
        FROM employee e
        LEFT JOIN (
            SELECT employee_id,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as total_present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as total_late,
                SUM(CASE WHEN status IN ('absent','awol','full_absent') THEN 1 ELSE 0 END) as total_absent,
                SUM(CASE WHEN status IN ('leave','paid_leave','unpaid_leave') THEN 1 ELSE 0 END) as total_leave
            FROM attendance WHERE attendance_date BETWEEN ? AND ? GROUP BY employee_id
        ) att_agg ON e.id = att_agg.employee_id
        LEFT JOIN (
            SELECT 1 as joint_key, SUM(sub.ot_hours) as total_ot_hours, SUM(sub.ot_pay) as total_ot_pay
            FROM (
                SELECT employee_id, total_hours as ot_hours, ot_pay
                FROM overtime_requests WHERE ot_date BETWEEN ? AND ? AND status = 'Approved'
                UNION ALL
                SELECT employee_id, total_hours as ot_hours, ot_pay
                FROM overtime_records WHERE ot_date BETWEEN ? AND ? AND status = 'Approved'
            ) sub
        ) ot_agg ON 1 = ot_agg.joint_key
        WHERE e.status = 'active'";

    $types = 'ssssss';
    $params = [$start, $end, $start, $end, $start, $end];

    if ($dept > 0) { $sql .= " AND e.department_id = ?"; $types .= 'i'; $params[] = $dept; }
    if ($search !== '') { $sql .= " AND e.name LIKE ?"; $types .= 's'; $params[] = "%$search%"; }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: ['total_employees' => 0, 'total_present' => 0, 'total_late' => 0, 'total_absent' => 0, 'total_leave' => 0, 'total_ot_hours' => 0, 'total_ot_pay' => 0];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Monthly Attendance Report</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .view-tab {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 600;
            color: #94A3B8; background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06); cursor: pointer;
            transition: all 0.2s ease; white-space: nowrap;
        }
        :root:not(.dark) .view-tab { color: #64748B; background: rgba(0,0,0,0.02); border-color: rgba(0,0,0,0.08); }
        .view-tab:hover { background: rgba(255,255,255,0.06); color: #E2E8F0; }
        :root:not(.dark) .view-tab:hover { background: rgba(0,0,0,0.04); color: #334155; }
        .view-tab-active { background: rgba(99,102,241,0.2) !important; border-color: rgba(99,102,241,0.3) !important; color: #818CF8 !important; }
        :root:not(.dark) .view-tab-active { background: rgba(79,70,229,0.1) !important; border-color: rgba(79,70,229,0.25) !important; color: #4F46E5 !important; }

        .detail-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.04); }
        :root:not(.dark) .detail-row { border-bottom-color: rgba(0,0,0,0.06); }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { display: flex; align-items: center; gap: 10px; font-size: 13px; color: #94A3B8; font-weight: 500; }
        :root:not(.dark) .detail-label { color: #64748B; }
        .detail-value { font-size: 14px; font-weight: 700; color: #F1F5F9; }
        :root:not(.dark) .detail-value { color: #1E293B; }

        .week-day-card { border-radius: 14px; padding: 16px; border: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.02); transition: all 0.2s; }
        :root:not(.dark) .week-day-card { border-color: rgba(0,0,0,0.08); background: rgba(255,255,255,0.8); }
        .week-day-card:hover { border-color: rgba(99,102,241,0.3); background: rgba(99,102,241,0.04); }
        .week-day-card.is-today { border-color: rgba(99,102,241,0.5); box-shadow: 0 0 0 1px rgba(99,102,241,0.2), 0 4px 16px rgba(99,102,241,0.1); }
        .week-day-card.is-weekend { opacity: 0.6; }

        .ot-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; }

        .emp-day-card { border-radius: 14px; padding: 16px; border: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.02); transition: all 0.25s; }
        :root:not(.dark) .emp-day-card { border-color: rgba(0,0,0,0.08); background: rgba(255,255,255,0.8); }
        .emp-day-card:hover { border-color: rgba(99,102,241,0.3); box-shadow: 0 4px 20px rgba(0,0,0,0.04); transform: translateY(-1px); }

        .mar-stat-card { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .mar-stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.06); }
        .sort-btn { cursor: pointer; user-select: none; }
        .sort-btn:hover { opacity: 0.8; }
        .ot-expand-row { display: none; }
        .ot-expand-row.active { display: table-row; }
        .ot-detail-table { width: 100%; font-size: 11px; }
        .ot-detail-table th { background: rgba(255,255,255,0.04); font-size: 10px; padding: 6px 12px; }
        .ot-detail-table td { padding: 6px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); }

        @media (max-width: 640px) {
            .detail-row { flex-direction: column; align-items: flex-start; gap: 4px; padding: 8px 0; }
            .week-day-card, .emp-day-card { padding: 12px; }
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Monthly Attendance Report"; $page_subtitle = "Attendance & Overtime overview"; include "../includes/topbar.php"; ?>
        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter" x-data="{ mode: '<?= $view_mode ?>' }">

            <!-- ═══ HEADER + VIEW TABS ═══ -->
            <div class="glass-strong rounded-2xl p-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center text-xl shadow-lg shadow-blue-500/25 shrink-0">
                            <i class="fa-solid fa-chart-bar"></i>
                        </div>
                        <div>
                            <h1 class="text-lg sm:text-xl font-extrabold text-white tracking-tight">Monthly Attendance Report</h1>
                            <p class="text-[11px] text-zinc-400 mt-0.5">
                                <?php echo date('F Y', strtotime($month_start)); ?>
                                <?php if ($view_mode === 'daily'): ?> &middot; <?= date('l, M j, Y', strtotime($selected_date)) ?>
                                <?php elseif ($view_mode === 'weekly'): ?> &middot; <?= date('M j', strtotime($week_start)) ?> – <?= date('M j, Y', strtotime($week_end)) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="<?php echo build_url(['export' => 'excel']); ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-semibold rounded-xl transition-colors shadow-sm">
                            <i class="fa-solid fa-file-excel"></i> <span class="hidden sm:inline">Export</span> Excel
                        </a>
                        <a href="<?php echo build_url(['export' => 'pdf']); ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-rose-500 hover:bg-rose-600 text-white text-xs font-semibold rounded-xl transition-colors shadow-sm">
                            <i class="fa-solid fa-file-pdf"></i> <span class="hidden sm:inline">Export</span> PDF
                        </a>
                    </div>
                </div>

                <!-- View Mode Tabs -->
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-white/[0.06]">
                    <div class="flex gap-1.5 p-1 bg-white/[0.04] rounded-xl">
                        <a href="<?= build_url(['view' => 'daily', 'page' => 1]) ?>" @click="mode = 'daily'" class="view-tab" :class="mode === 'daily' && 'view-tab-active'">
                            <i class="fa-solid fa-sun text-xs"></i> Daily
                        </a>
                        <a href="<?= build_url(['view' => 'weekly', 'page' => 1]) ?>" @click="mode = 'weekly'" class="view-tab" :class="mode === 'weekly' && 'view-tab-active'">
                            <i class="fa-solid fa-calendar-week text-xs"></i> Weekly
                        </a>
                        <a href="<?= build_url(['view' => 'monthly', 'page' => 1]) ?>" @click="mode = 'monthly'" class="view-tab" :class="mode === 'monthly' && 'view-tab-active'">
                            <i class="fa-solid fa-calendar-days text-xs"></i> Monthly
                        </a>
                    </div>

                    <!-- Date Navigation -->
                    <div class="flex items-center gap-2 ml-auto">
                        <?php if ($view_mode === 'daily'): ?>
                            <?php $prev_d = date('Y-m-d', strtotime($selected_date . ' -1 day')); $next_d = date('Y-m-d', strtotime($selected_date . ' +1 day')); ?>
                            <a href="<?= build_url(['date' => $prev_d, 'page' => 1]) ?>" class="w-8 h-8 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 transition-all group"><i class="fa-solid fa-chevron-left text-xs text-zinc-400 group-hover:text-indigo-400"></i></a>
                            <input type="date" value="<?= $selected_date ?>" onchange="window.location.href='<?= build_url(['page' => 1]) ?>&date=' + this.value" class="h-8 px-3 glass rounded-lg border border-white/[0.08] text-sm text-white text-center font-semibold cursor-pointer focus:outline-none focus:border-indigo-500/50 transition-all">
                            <a href="<?= build_url(['date' => $next_d, 'page' => 1]) ?>" class="w-8 h-8 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 transition-all group"><i class="fa-solid fa-chevron-right text-xs text-zinc-400 group-hover:text-indigo-400"></i></a>
                            <?php if ($selected_date !== $today): ?>
                                <a href="<?= build_url(['date' => $today, 'page' => 1]) ?>" class="h-8 px-3 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-emerald-500/40 transition-all group gap-1">
                                    <i class="fa-solid fa-crosshairs text-[10px] text-zinc-400 group-hover:text-emerald-400"></i>
                                    <span class="text-[11px] font-semibold text-zinc-400 group-hover:text-emerald-400">Today</span>
                                </a>
                            <?php endif; ?>
                        <?php elseif ($view_mode === 'weekly'): ?>
                            <?php $prev_w = date('Y-m-d', strtotime($week_start . ' -7 days')); $next_w = date('Y-m-d', strtotime($week_start . ' +7 days')); ?>
                            <a href="<?= build_url(['date' => $prev_w, 'page' => 1]) ?>" class="w-8 h-8 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 transition-all group"><i class="fa-solid fa-chevron-left text-xs text-zinc-400 group-hover:text-indigo-400"></i></a>
                            <span class="text-sm font-semibold text-zinc-300 px-2"><?= date('M j', strtotime($week_start)) ?> – <?= date('M j, Y', strtotime($week_end)) ?></span>
                            <a href="<?= build_url(['date' => $next_w, 'page' => 1]) ?>" class="w-8 h-8 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 transition-all group"><i class="fa-solid fa-chevron-right text-xs text-zinc-400 group-hover:text-indigo-400"></i></a>
                        <?php else: ?>
                            <a href="<?= build_url(['month' => $selected_month == 1 ? 12 : $selected_month - 1, 'year' => $selected_month == 1 ? $selected_year - 1 : $selected_year, 'page' => 1]) ?>" class="w-8 h-8 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 transition-all group"><i class="fa-solid fa-chevron-left text-xs text-zinc-400 group-hover:text-indigo-400"></i></a>
                            <span class="text-sm font-semibold text-zinc-300 px-2"><?= date('F Y', strtotime($month_start)) ?></span>
                            <a href="<?= build_url(['month' => $selected_month == 12 ? 1 : $selected_month + 1, 'year' => $selected_month == 12 ? $selected_year + 1 : $selected_year, 'page' => 1]) ?>" class="w-8 h-8 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 transition-all group"><i class="fa-solid fa-chevron-right text-xs text-zinc-400 group-hover:text-indigo-400"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ═══ FILTERS ═══ -->
            <form method="GET" class="glass-strong rounded-2xl p-4 sm:p-5">
                <input type="hidden" name="view" value="<?= $view_mode ?>">
                <?php if ($view_mode === 'daily'): ?><input type="hidden" name="date" value="<?= $selected_date ?>"><?php endif; ?>
                <?php if ($view_mode === 'weekly'): ?><input type="hidden" name="date" value="<?= $selected_date ?>"><?php endif; ?>
                <?php if ($view_mode === 'monthly'): ?>
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_col) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($sort_dir) ?>">
                <?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-zinc-500 uppercase tracking-wider mb-1.5">Month</label>
                        <select name="month" onchange="this.form.submit()" class="w-full bg-white/[0.04] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/30 transition-colors">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $selected_month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-zinc-500 uppercase tracking-wider mb-1.5">Year</label>
                        <select name="year" onchange="this.form.submit()" class="w-full bg-white/[0.04] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/30 transition-colors">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?= $y ?>" <?= $y === $selected_year ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-zinc-500 uppercase tracking-wider mb-1.5">Department</label>
                        <select name="department" class="w-full bg-white/[0.04] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/30 transition-colors">
                            <option value="0">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $selected_dept === (int)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-zinc-500 uppercase tracking-wider mb-1.5">Search Employee</label>
                        <div class="flex gap-2">
                            <input type="text" name="search" value="<?= htmlspecialchars($search_name) ?>" placeholder="Employee name..." class="flex-1 bg-white/[0.04] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/30 placeholder:text-zinc-500 transition-colors">
                            <button type="submit" class="px-4 py-2.5 bg-gradient-to-r from-blue-500 to-indigo-500 hover:from-blue-600 hover:to-indigo-600 text-white font-semibold text-sm rounded-xl shadow-sm transition-all hover:scale-[1.02]">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <!-- ═══════════════════════════════════════════ -->
            <!-- DAILY VIEW -->
            <!-- ═══════════════════════════════════════════ -->
            <div x-show="mode === 'daily'" x-transition:enter="transition-all duration-200">
                <?php if (!empty($daily_records)): ?>
                <div class="space-y-3">
                    <?php foreach ($daily_records as $dr):
                        $status_badge = get_attendance_status_badge_class($dr['status'] ?? 'absent');
                        $status_label = get_attendance_status_label($dr['status'] ?? 'absent');
                        $has_ot = (float)$dr['ot_hours'] > 0;
                        $ot_types = array_filter(array_unique(explode(',', $dr['ot_type'] ?? '')));
                    ?>
                    <div class="emp-day-card">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                            <!-- Employee Info -->
                            <div class="flex items-center gap-3 sm:min-w-[200px]">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500/20 to-purple-500/20 text-indigo-400 flex items-center justify-center text-sm font-bold shrink-0">
                                    <?= strtoupper(substr($dr['name'], 0, 2)) ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-white truncate"><?= htmlspecialchars($dr['name']) ?></p>
                                    <p class="text-[10px] text-zinc-400 truncate"><?= htmlspecialchars($dr['department_name']) ?></p>
                                </div>
                            </div>

                            <!-- Attendance Details -->
                            <div class="flex-1 grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div class="text-center sm:text-left">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 mb-1">Status</p>
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold <?= $status_badge ?>"><?= $status_label ?></span>
                                </div>
                                <div class="text-center sm:text-left">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 mb-1">Check-In</p>
                                    <p class="text-sm font-semibold <?= $dr['check_in'] ? 'text-emerald-400' : 'text-zinc-500' ?>">
                                        <?= $dr['check_in'] ? date('h:i A', strtotime($dr['check_in'])) : '<span class="text-zinc-600">-</span>' ?>
                                    </p>
                                </div>
                                <div class="text-center sm:text-left">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 mb-1">Check-Out</p>
                                    <p class="text-sm font-semibold <?= $dr['check_out'] ? 'text-rose-400' : 'text-zinc-500' ?>">
                                        <?= $dr['check_out'] ? date('h:i A', strtotime($dr['check_out'])) : '<span class="text-zinc-600">-</span>' ?>
                                    </p>
                                </div>
                                <div class="text-center sm:text-left">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 mb-1">Hours</p>
                                    <p class="text-sm font-bold text-indigo-400">
                                        <?php
                                        if ($dr['total_working_hours']) echo number_format((float)$dr['total_working_hours'], 1) . 'h';
                                        elseif ($dr['check_in'] && $dr['check_out']) echo number_format((strtotime($dr['check_out']) - strtotime($dr['check_in'])) / 3600, 1) . 'h';
                                        else echo '<span class="text-zinc-600">-</span>';
                                        ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Overtime Details -->
                            <div class="flex items-center gap-4 sm:min-w-[220px] justify-end">
                                <?php if ($has_ot): ?>
                                <div class="text-right">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 mb-1">Overtime</p>
                                    <div class="flex flex-wrap gap-1 justify-end mb-1">
                                        <?php foreach ($ot_types as $t):
                                            $c = ot_type_color($t);
                                        ?>
                                        <span class="ot-chip <?= $c['bg'] ?> <?= $c['text'] ?> border <?= $c['border'] ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $c['dot'] ?>"></span>
                                            <?= ot_type_label($t) ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="text-xs text-zinc-400">
                                        <?= number_format((float)$dr['ot_hours'], 1) ?>h
                                        (<?= date('h:i A', strtotime($dr['ot_start'])) ?> – <?= date('h:i A', strtotime($dr['ot_end'])) ?>)
                                    </p>
                                    <?php if ((float)$dr['ot_pay'] > 0): ?>
                                    <p class="text-sm font-bold text-emerald-400 mt-1">$<?= number_format((float)$dr['ot_pay'], 2) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="text-right">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 mb-1">Overtime</p>
                                    <span class="text-zinc-600 text-xs">None</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="glass-strong rounded-2xl p-12 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-white/[0.03] flex items-center justify-center mx-auto mb-4">
                        <i class="fa-solid fa-calendar-xmark text-2xl text-zinc-600"></i>
                    </div>
                    <p class="text-zinc-400 font-medium">No records found</p>
                    <p class="text-xs text-zinc-500 mt-1">No attendance data for this date.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!-- WEEKLY VIEW -->
            <!-- ═══════════════════════════════════════════ -->
            <div x-show="mode === 'weekly'" x-transition:enter="transition-all duration-200" style="display: none;">
                <!-- Weekly Summary -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-emerald-500/20 bg-emerald-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-emerald-500/10 flex items-center justify-center"><i class="fa-solid fa-check-circle text-emerald-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-white"><?= $weekly_summary['present'] ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">Present</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-amber-500/20 bg-amber-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-amber-500/10 flex items-center justify-center"><i class="fa-solid fa-clock text-amber-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-white"><?= $weekly_summary['late'] ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">Late</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-red-500/20 bg-red-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-red-500/10 flex items-center justify-center"><i class="fa-solid fa-user-xmark text-red-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-white"><?= $weekly_summary['absent'] ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">Absent</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-orange-500/20 bg-orange-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-orange-500/10 flex items-center justify-center"><i class="fa-solid fa-stopwatch text-orange-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-orange-400"><?= number_format($weekly_summary['ot_hours'], 1) ?>h</p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">OT Hours</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-emerald-500/20 bg-emerald-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-emerald-500/10 flex items-center justify-center"><i class="fa-solid fa-dollar-sign text-emerald-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-emerald-400">$<?= number_format($weekly_summary['ot_pay'], 2) ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">OT Pay</p>
                    </div>
                </div>

                <!-- Day-by-Day Employee Grid -->
                <?php for ($i = 0; $i < 7; $i++):
                    $d = date('Y-m-d', strtotime($week_start . " +$i days"));
                    $day_data = $weekly_daily_data[$d] ?? [];
                    $day_name = date('D', strtotime($d));
                    $day_num = date('j', strtotime($d));
                    $day_month = date('M', strtotime($d));
                    $is_weekend = (int)date('N', strtotime($d)) >= 6;
                    $is_today = ($d === $today);
                    $day_present = 0; $day_late = 0; $day_absent = 0; $day_ot_h = 0;
                    foreach ($day_data as $dd) {
                        if (in_array($dd['status'], ['present', 'late', 'half_day'])) $day_present++;
                        if ($dd['status'] === 'late') $day_late++;
                        if (in_array($dd['status'], ['absent', 'awol', 'full_absent'])) $day_absent++;
                        $day_ot_h += (float)$dd['ot_hours'];
                    }
                ?>
                <div class="mb-6">
                    <div class="flex items-center gap-3 mb-3">
                        <a href="<?= build_url(['date' => $d, 'view' => 'daily', 'page' => 1]) ?>" class="flex items-center gap-2 hover:no-underline">
                            <span class="text-sm font-extrabold text-white"><?= $day_name ?></span>
                            <span class="text-xs text-zinc-400"><?= $day_month ?> <?= $day_num ?></span>
                            <?php if ($is_today): ?>
                                <span class="text-[9px] font-bold uppercase tracking-wider text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-full">Today</span>
                            <?php endif; ?>
                        </a>
                        <div class="flex items-center gap-2 ml-auto text-[10px] font-semibold">
                            <span class="text-emerald-400"><i class="fa-solid fa-user-check mr-1"></i><?= $day_present ?></span>
                            <span class="text-amber-400"><i class="fa-solid fa-clock mr-1"></i><?= $day_late ?></span>
                            <span class="text-red-400"><i class="fa-solid fa-user-xmark mr-1"></i><?= $day_absent ?></span>
                            <?php if ($day_ot_h > 0): ?>
                                <span class="text-orange-400"><i class="fa-solid fa-stopwatch mr-1"></i><?= number_format($day_ot_h, 1) ?>h</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($day_data)): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                        <?php foreach ($day_data as $dr):
                            $status_badge = get_attendance_status_badge_class($dr['status'] ?? 'absent');
                            $status_label = get_attendance_status_label($dr['status'] ?? 'absent');
                            $has_ot = (float)$dr['ot_hours'] > 0;
                            $ot_types = array_filter(array_unique(explode(',', $dr['ot_type'] ?? '')));
                        ?>
                        <div class="emp-day-card">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500/20 to-purple-500/20 text-indigo-400 flex items-center justify-center text-[10px] font-bold shrink-0">
                                    <?= strtoupper(substr($dr['name'], 0, 2)) ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($dr['name']) ?></p>
                                    <p class="text-[9px] text-zinc-500 truncate"><?= htmlspecialchars($dr['department_name']) ?></p>
                                </div>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-semibold <?= $status_badge ?> shrink-0"><?= $status_label ?></span>
                            </div>
                            <div class="grid grid-cols-3 gap-1 text-[10px] mb-1">
                                <div><span class="text-zinc-500">In:</span> <span class="font-semibold <?= $dr['check_in'] ? 'text-emerald-400' : 'text-zinc-600' ?>"><?= $dr['check_in'] ? date('h:i A', strtotime($dr['check_in'])) : '-' ?></span></div>
                                <div><span class="text-zinc-500">Out:</span> <span class="font-semibold <?= $dr['check_out'] ? 'text-rose-400' : 'text-zinc-600' ?>"><?= $dr['check_out'] ? date('h:i A', strtotime($dr['check_out'])) : '-' ?></span></div>
                                <div><span class="text-zinc-500">Hrs:</span> <span class="font-semibold text-indigo-400"><?= $dr['total_working_hours'] ? number_format((float)$dr['total_working_hours'], 1) . 'h' : '-' ?></span></div>
                            </div>
                            <?php if ($has_ot): ?>
                            <div class="pt-1.5 mt-1.5 border-t border-white/[0.06] flex items-center justify-between">
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach ($ot_types as $t):
                                        $c = ot_type_color($t);
                                    ?>
                                    <span class="ot-chip <?= $c['bg'] ?> <?= $c['text'] ?> text-[9px] border <?= $c['border'] ?>">
                                        <?= number_format((float)$dr['ot_hours'], 1) ?>h
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ((float)$dr['ot_pay'] > 0): ?>
                                    <span class="text-[9px] font-semibold text-emerald-400">$<?= number_format((float)$dr['ot_pay'], 2) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-3 text-xs text-zinc-500">
                        <?= $is_weekend ? 'Weekend - No records' : 'No data for this day' ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endfor; ?>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!-- MONTHLY VIEW -->
            <!-- ═══════════════════════════════════════════ -->
            <div x-show="mode === 'monthly'" x-transition:enter="transition-all duration-200" style="display: none;">
                <!-- Summary Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3">
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-white/[0.06] text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-indigo-500/10 flex items-center justify-center"><i class="fa-solid fa-users text-indigo-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-white"><?= (int)$summary['total_employees'] ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">Employees</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-emerald-500/20 bg-emerald-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-emerald-500/10 flex items-center justify-center"><i class="fa-solid fa-check-circle text-emerald-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-emerald-400"><?= (int)$summary['total_present'] ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">Present</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-amber-500/20 bg-amber-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-amber-500/10 flex items-center justify-center"><i class="fa-solid fa-clock text-amber-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-amber-400"><?= (int)$summary['total_late'] ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">Late</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-red-500/20 bg-red-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-red-500/10 flex items-center justify-center"><i class="fa-solid fa-user-xmark text-red-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-red-400"><?= (int)$summary['total_absent'] ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">Absent</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-blue-500/20 bg-blue-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-blue-500/10 flex items-center justify-center"><i class="fa-solid fa-calendar-xmark text-blue-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-blue-400"><?= (int)$summary['total_leave'] ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">Leave</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-purple-500/20 bg-purple-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-purple-500/10 flex items-center justify-center"><i class="fa-solid fa-stopwatch text-purple-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-purple-400"><?= number_format($summary['total_ot_hours'], 1) ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">OT Hours</p>
                    </div>
                    <div class="mar-stat-card glass-strong rounded-xl p-4 border border-teal-500/20 bg-teal-500/5 text-center">
                        <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-teal-500/10 flex items-center justify-center"><i class="fa-solid fa-dollar-sign text-teal-400 text-sm"></i></div>
                        <p class="text-xl font-extrabold text-teal-400">$<?= number_format($summary['total_ot_pay'], 2) ?></p>
                        <p class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider mt-0.5">OT Pay</p>
                    </div>
                </div>

                <!-- Employee Table -->
                <div class="glass-strong rounded-2xl overflow-hidden">
                    <div class="px-4 sm:px-5 py-4 border-b border-white/[0.06] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-table text-blue-400"></i>
                            Attendance Records
                            <span class="text-[10px] font-semibold text-zinc-500 ml-1">(<?= $total_records ?> employee<?= $total_records !== 1 ? 's' : '' ?>)</span>
                        </h3>
                        <p class="text-[10px] text-zinc-500">Page <?= $page ?> of <?= $total_pages ?></p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="bg-white/[0.02] border-b border-white/[0.06]">
                                    <?php
                                    $sort_headers = [
                                        'name' => ['Employee', 'name'],
                                        'department_name' => ['Department', 'department_name'],
                                        'present_days' => ['Present', 'present_days'],
                                        'late_days' => ['Late', 'late_days'],
                                        'absent_days' => ['Absent', 'absent_days'],
                                        'ot_hours' => ['OT Hours', 'ot_hours'],
                                        'ot_pay' => ['OT Pay', 'ot_pay'],
                                    ];
                                    foreach ($sort_headers as $key => $label_info):
                                        $label = $label_info[0];
                                        $sort_key = $label_info[1];
                                    ?>
                                    <th class="px-3 sm:px-4 py-3 text-[10px] font-bold text-zinc-400 uppercase tracking-wider <?= $sort_key ? 'sort-btn' : '' ?>" <?= $sort_key ? "onclick=\"window.location.href='" . htmlspecialchars(build_url(['sort' => $sort_key, 'dir' => ($sort_col === $sort_key && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1])) . "'" : '' ?>>
                                        <?= $label ?>
                                        <?php if ($sort_key && $sort_col === $sort_key): ?>
                                        <i class="fa-solid fa-caret-<?= $sort_dir === 'ASC' ? 'up' : 'down' ?> ml-0.5 text-blue-400"></i>
                                        <?php endif; ?>
                                    </th>
                                    <?php endforeach; ?>
                                    <th class="px-3 sm:px-4 py-3 text-[10px] font-bold text-zinc-400 uppercase tracking-wider text-center w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04]">
                                <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-16 text-center">
                                        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-white/[0.04] flex items-center justify-center">
                                            <i class="fa-solid fa-chart-line text-2xl text-zinc-600"></i>
                                        </div>
                                        <p class="text-sm font-semibold text-zinc-400">No records found</p>
                                        <p class="text-xs text-zinc-500 mt-1">Try adjusting your filters.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($records as $idx => $r):
                                    $has_ot = (float)$r['ot_hours'] > 0;
                                ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-3 sm:px-4 py-3">
                                        <p class="text-xs sm:text-sm font-semibold text-white"><?= htmlspecialchars($r['name']) ?></p>
                                    </td>
                                    <td class="px-3 sm:px-4 py-3">
                                        <span class="text-xs text-zinc-400"><?= htmlspecialchars($r['department_name']) ?></span>
                                    </td>
                                    <td class="px-3 sm:px-4 py-3 text-center">
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-emerald-500/10 text-xs font-bold text-emerald-400"><?= $r['present_days'] ?></span>
                                    </td>
                                    <td class="px-3 sm:px-4 py-3 text-center">
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-amber-500/10 text-xs font-bold text-amber-400"><?= $r['late_days'] ?></span>
                                    </td>
                                    <td class="px-3 sm:px-4 py-3 text-center">
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-red-500/10 text-xs font-bold text-red-400"><?= $r['absent_days'] ?></span>
                                    </td>
                                    <td class="px-3 sm:px-4 py-3 text-center">
                                        <?php if ($has_ot): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-purple-500/10 text-[10px] font-bold text-purple-400 font-mono">
                                            <i class="fa-solid fa-stopwatch"></i><?= hours_to_time($r['ot_hours']) ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-xs text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 sm:px-4 py-3 text-center">
                                        <?php if ($has_ot): ?>
                                        <span class="text-xs font-bold text-teal-400">$<?= number_format($r['ot_pay'], 2) ?></span>
                                        <?php else: ?>
                                        <span class="text-xs text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 sm:px-4 py-3 text-center">
                                        <?php if ($has_ot): ?>
                                        <button onclick="toggleOTDetails(this, <?= (int)$r['id'] ?>)" class="p-1.5 text-zinc-400 hover:text-purple-400 rounded-lg hover:bg-purple-500/10 transition-colors" title="View OT Details">
                                            <i class="fa-solid fa-chevron-down text-[10px] ot-chevron"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($has_ot): ?>
                                <tr class="ot-expand-row" id="ot-detail-<?= (int)$r['id'] ?>">
                                    <td colspan="8" class="px-4 py-3 bg-white/[0.015]">
                                        <div class="ml-4 border-l-2 border-purple-500/30 pl-4">
                                            <p class="text-[10px] font-bold text-purple-400 uppercase tracking-wider mb-2"><i class="fa-solid fa-stopwatch mr-1"></i>Approved Overtime Details</p>
                                            <div class="ot-detail-loading text-xs text-zinc-400 py-2">Loading OT details...</div>
                                            <table class="ot-detail-table hidden">
                                                <thead>
                                                    <tr>
                                                        <th class="text-left">OT Date</th>
                                                        <th class="text-left">Type</th>
                                                        <th class="text-center">Hours</th>
                                                        <th class="text-left">Time</th>
                                                        <th class="text-right">Pay</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="ot-detail-body"></tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="px-4 sm:px-5 py-4 border-t border-white/[0.06] flex flex-col sm:flex-row items-center justify-between gap-3">
                        <p class="text-xs text-zinc-500">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_records) ?> of <?= $total_records ?></p>
                        <div class="flex items-center gap-1.5">
                            <?php if ($page > 1): ?>
                            <a href="<?= build_url(['page' => 1]) ?>" class="px-3 py-1.5 text-xs font-semibold text-zinc-400 bg-white/[0.06] hover:bg-white/[0.1] rounded-lg transition-colors"><i class="fa-solid fa-angles-left"></i></a>
                            <a href="<?= build_url(['page' => $page - 1]) ?>" class="px-3 py-1.5 text-xs font-semibold text-zinc-400 bg-white/[0.06] hover:bg-white/[0.1] rounded-lg transition-colors"><i class="fa-solid fa-chevron-left mr-1"></i>Prev</a>
                            <?php endif; ?>
                            <?php
                            $start_p = max(1, $page - 2);
                            $end_p = min($total_pages, $page + 2);
                            for ($p = $start_p; $p <= $end_p; $p++):
                            ?>
                            <a href="<?= build_url(['page' => $p]) ?>" class="w-8 h-8 flex items-center justify-center text-xs font-semibold rounded-lg transition-colors <?= $p === $page ? 'bg-blue-500 text-white shadow-sm shadow-blue-500/30' : 'text-zinc-400 bg-white/[0.06] hover:bg-white/[0.1]' ?>"><?= $p ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                            <a href="<?= build_url(['page' => $page + 1]) ?>" class="px-3 py-1.5 text-xs font-semibold text-zinc-400 bg-white/[0.06] hover:bg-white/[0.1] rounded-lg transition-colors">Next<i class="fa-solid fa-chevron-right ml-1"></i></a>
                            <a href="<?= build_url(['page' => $total_pages]) ?>" class="px-3 py-1.5 text-xs font-semibold text-zinc-400 bg-white/[0.06] hover:bg-white/[0.1] rounded-lg transition-colors"><i class="fa-solid fa-angles-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <script>
    const otCache = {};
    function toggleOTDetails(btn, empId) {
        const row = document.getElementById('ot-detail-' + empId);
        if (!row) return;
        const isOpen = row.classList.contains('active');
        document.querySelectorAll('.ot-expand-row.active').forEach(r => r.classList.remove('active'));
        document.querySelectorAll('.ot-chevron').forEach(c => c.style.transform = '');
        if (isOpen) return;
        row.classList.add('active');
        btn.querySelector('.ot-chevron').style.transform = 'rotate(180deg)';
        if (otCache[empId]) { renderOTDetails(row, otCache[empId]); return; }
        fetch('monthly_attendance_report.php?ajax_ot=1&emp_id=' + empId + '&month=<?= $selected_month ?>&year=<?= $selected_year ?>')
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(text => { try { return JSON.parse(text); } catch(e) { throw new Error('Invalid JSON: ' + text.substring(0, 200)); } })
            .then(data => { otCache[empId] = data; renderOTDetails(row, data); })
            .catch(e => { row.querySelector('.ot-detail-loading').innerHTML = '<span class="text-red-400"><i class="fa-solid fa-circle-exclamation mr-1"></i>Failed to load OT details: ' + e.message + '</span>'; });
    }
    function renderOTDetails(row, data) {
        const loading = row.querySelector('.ot-detail-loading');
        const table = row.querySelector('.ot-detail-table');
        const tbody = row.querySelector('.ot-detail-body');
        loading.classList.add('hidden');
        if (!data.length) { loading.textContent = 'No OT records found.'; loading.classList.remove('hidden'); return; }
        const typeLabels = { working_day: 'Working Day', weekday: 'Weekday', weekend: 'Weekend', holiday: 'Holiday' };
        tbody.innerHTML = data.map(r => `<tr>
            <td class="font-semibold text-zinc-300">${new Date(r.ot_date).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'})}</td>
            <td><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-500/10 text-purple-400">${typeLabels[r.ot_type] || r.ot_type || 'N/A'}</span></td>
            <td class="text-center font-semibold text-zinc-300">${parseFloat(r.total_hours).toFixed(1)}h</td>
            <td class="text-zinc-400">${r.start_time ? new Date('2000-01-01T'+r.start_time).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'}) + ' – ' + new Date('2000-01-01T'+r.end_time).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'}) : '—'}</td>
            <td class="text-right font-bold text-teal-400">$${parseFloat(r.ot_pay).toFixed(2)}</td>
        </tr>`).join('');
        table.classList.remove('hidden');
    }
    </script>
</body>
</html>
