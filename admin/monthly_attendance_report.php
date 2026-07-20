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
$selected_month = isset($_GET['month']) && in_array((int)$_GET['month'], range(1, 12)) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) && (int)$_GET['year'] >= 2020 && (int)$_GET['year'] <= 2100 ? (int)$_GET['year'] : (int)date('Y');
$selected_dept = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$search_name = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$per_page = 10;

$valid_sort_cols = ['name', 'department_name', 'present_days', 'late_days', 'absent_days', 'awol_days', 'ot_hours', 'ot_pay'];
$sort_col = isset($_GET['sort']) && in_array($_GET['sort'], $valid_sort_cols) ? $_GET['sort'] : 'name';
$sort_dir = isset($_GET['dir']) && strtoupper($_GET['dir']) === 'DESC' ? 'DESC' : 'ASC';

$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

// Week pagination: compute all weeks in the month
$weeks = [];
$m_start = new DateTime($month_start);
$m_end = new DateTime($month_end);
$m_end_week = (int)$m_end->format('W');
$m_end_year = (int)$m_end->format('o');
$curr = clone $m_start;
$curr->modify('monday this week');
if ($curr->format('o') < (int)$selected_year) $curr->setISODate($selected_year, 1);
$wi = 0;
while ($curr <= $m_end) {
    $week_end_date = clone $curr;
    $week_end_date->modify('sunday');
    if ($week_end_date > $m_end) $week_end_date = $m_end;
    $label = 'Week ' . $curr->format('W') . ' (' . $curr->format('M d') . ' - ' . $week_end_date->format('M d, Y') . ')';
    $weeks[$wi] = [
        'start' => $curr->format('Y-m-d'),
        'end' => $week_end_date->format('Y-m-d'),
        'label' => $label,
        'w' => $curr->format('W'),
    ];
    $wi++;
    $curr->modify('+7 days');
}
$total_weeks = count($weeks);

$week_offset = 0;
if (isset($_GET['week']) && is_numeric($_GET['week'])) {
    $w = (int)$_GET['week'];
    if ($w >= 0 && $w < $total_weeks) $week_offset = $w;
}

// Show last week by default
if ($total_weeks > 0 && !isset($_GET['week'])) {
    $week_offset = $total_weeks - 1;
}

// Override month_start/month_end to show only the selected week
if ($total_weeks > 1) {
    $w = $weeks[$week_offset];
    // store actual month bounds for display
    $actual_month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
    $actual_month_end = date('Y-m-t', strtotime($actual_month_start));
    $month_start = $w['start'];
    $month_end = $w['end'];
} else {
    $actual_month_start = $month_start;
    $actual_month_end = $month_end;
}

$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

// ─── Handle Export ───────────────────────────────────────────────
$export_mode = $_GET['export'] ?? '';
if (in_array($export_mode, ['excel', 'pdf']) && in_array($sort_col, $valid_sort_cols)) {
    $export_data = fetch_report_data($conn, $month_start, $month_end, $selected_dept, $search_name, $sort_col, $sort_dir, 0, 99999);

    // Fetch OT details per employee for the export (from both tables)
    $ot_details = [];
    if (!empty($export_data)) {
        $emp_ids = array_column($export_data, 'id');
        $placeholders = implode(',', array_fill(0, count($emp_ids), '?'));
        $types = str_repeat('i', count($emp_ids)) . 'ss';
        $params = array_merge($emp_ids, [$month_start, $month_end]);
        $ot_stmt = $conn->prepare("SELECT employee_id, ot_date, ot_type, total_hours, status, ot_pay FROM overtime_requests WHERE employee_id IN ($placeholders) AND ot_date BETWEEN ? AND ? AND status = 'Approved'
            UNION ALL
            SELECT employee_id, ot_date, ot_type, total_hours, status, ot_pay FROM overtime_records WHERE employee_id IN ($placeholders) AND ot_date BETWEEN ? AND ? AND status = 'Approved'
            ORDER BY employee_id, ot_date");
        $all_types = str_repeat('i', count($emp_ids)) . 'ss' . str_repeat('i', count($emp_ids)) . 'ss';
        $all_params = array_merge($emp_ids, [$month_start, $month_end, $emp_ids, $month_start, $month_end]);
        $ot_stmt->execute();
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

// ─── Fetch Data ──────────────────────────────────────────────────
$total_records = count_report_records($conn, $month_start, $month_end, $selected_dept, $search_name);
$total_pages = max(1, ceil($total_records / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$records = fetch_report_data($conn, $month_start, $month_end, $selected_dept, $search_name, $sort_col, $sort_dir, $per_page, $offset);

$summary = fetch_summary($conn, $month_start, $month_end, $selected_dept, $search_name);

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
        'awol_days' => 'att.awol_days',
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

function export_report_excel(array $data, array $ot_details, int $month, int $year): void {
    $month_name = date('F', mktime(0, 0, 0, $month, 1));
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Monthly_Attendance_Report_' . $month_name . '_' . $year . '.xls"');
    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<h2>Monthly Attendance Report - ' . $month_name . ' ' . $year . '</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
            echo '<tr style="background:#1e293b;color:white;"><th>Employee Name</th><th>Department</th><th>Position</th><th>Present</th><th>Late</th><th>Half Day</th><th>Absent</th><th>AWOL</th><th>Paid Leave</th><th>Unpaid Leave</th><th>Holiday</th><th>Weekend</th><th>Work Hours</th><th>OT Hours</th><th>OT Pay</th></tr>';
    foreach ($data as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['department_name']) . '</td>';
        echo '<td>' . htmlspecialchars($r['position_name']) . '</td>';
        echo '<td>' . $r['present_days'] . '</td>';
        echo '<td>' . $r['late_days'] . '</td>';
        echo '<td>' . $r['half_days'] . '</td>';
        echo '<td>' . $r['absent_days'] . '</td>';
        echo '<td>' . ($r['awol_days'] ?? 0) . '</td>';
        echo '<td>' . $r['paid_leave_days'] . '</td>';
        echo '<td>' . $r['unpaid_leave_days'] . '</td>';
        echo '<td>' . $r['holiday_days'] . '</td>';
        echo '<td>' . $r['weekend_days'] . '</td>';
        echo '<td>' . hours_to_time($r['total_hours']) . '</td>';
        echo '<td>' . hours_to_time($r['ot_hours']) . '</td>';
        echo '<td>$' . number_format($r['ot_pay'], 2) . '</td>';
        echo '</tr>';
        // OT detail sub-rows
        $emp_ot = $ot_details[(int)$r['id']] ?? [];
        if (!empty($emp_ot)) {
            foreach ($emp_ot as $ot) {
                echo '<tr style="background:#f0f9ff;">';
                echo '<td colspan="11" style="text-align:right;font-size:10px;color:#6b7280;">OT - ' . htmlspecialchars($ot['ot_date']) . '</td>';
                echo '<td style="text-align:center;font-size:10px;">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $ot['ot_type']))) . '</td>';
                echo '<td style="text-align:center;font-size:10px;">' . number_format($ot['total_hours'], 1) . 'h</td>';
                echo '<td style="font-size:10px;">' . htmlspecialchars($ot['status']) . '</td>';
                echo '<td style="text-align:right;font-size:10px;">$' . number_format($ot['ot_pay'], 2) . '</td>';
                echo '</tr>';
            }
        }
    }
    echo '</table></body></html>';
    exit;
}

function export_report_pdf(array $data, array $ot_details, int $month, int $year): void {
    require_once __DIR__ . '/../vendor/autoload.php';
    $month_name = date('F', mktime(0, 0, 0, $month, 1));

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'landscape');

    $rows_html = '';
    foreach ($data as $r) {
        $rows_html .= '<tr>';
        $rows_html .= '<td>' . htmlspecialchars($r['name']) . '</td>';
        $rows_html .= '<td>' . htmlspecialchars($r['department_name']) . '</td>';
        $rows_html .= '<td>' . htmlspecialchars($r['position_name']) . '</td>';
        $rows_html .= '<td class="c">' . $r['present_days'] . '</td>';
        $rows_html .= '<td class="c">' . $r['late_days'] . '</td>';
        $rows_html .= '<td class="c">' . $r['half_days'] . '</td>';
        $rows_html .= '<td class="c">' . $r['absent_days'] . '</td>';
        $rows_html .= '<td class="c">' . ($r['awol_days'] ?? 0) . '</td>';
        $rows_html .= '<td class="c">' . $r['paid_leave_days'] . '</td>';
        $rows_html .= '<td class="c">' . $r['unpaid_leave_days'] . '</td>';
        $rows_html .= '<td class="c">' . $r['holiday_days'] . '</td>';
        $rows_html .= '<td class="c">' . $r['weekend_days'] . '</td>';
        $rows_html .= '<td class="c">' . hours_to_time($r['total_hours']) . '</td>';
        $rows_html .= '<td class="c">' . hours_to_time($r['ot_hours']) . '</td>';
        $rows_html .= '<td class="r">$' . number_format($r['ot_pay'], 2) . '</td>';
        $rows_html .= '</tr>';
        // OT detail sub-rows
        $emp_ot = $ot_details[(int)$r['id']] ?? [];
        if (!empty($emp_ot)) {
            foreach ($emp_ot as $ot) {
                $type_label = ucfirst(str_replace('_', ' ', $ot['ot_type']));
                $rows_html .= '<tr class="ot-detail">';
                $rows_html .= '<td colspan="11" style="text-align:right;font-size:8px;color:#6b7280;">OT - ' . htmlspecialchars($ot['ot_date']) . '</td>';
                $rows_html .= '<td class="c" style="font-size:8px;">' . $type_label . '</td>';
                $rows_html .= '<td class="c" style="font-size:8px;">' . number_format($ot['total_hours'], 1) . 'h</td>';
                $rows_html .= '<td class="c" style="font-size:8px;">' . htmlspecialchars($ot['status']) . '</td>';
                $rows_html .= '<td class="r" style="font-size:8px;">$' . number_format($ot['ot_pay'], 2) . '</td>';
                $rows_html .= '</tr>';
            }
        }
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body{font-family:Helvetica,Arial,sans-serif;color:#1e293b;font-size:10px;margin:0;padding:10px;}
        h1{font-size:16px;margin:0 0 2px;}
        .sub{font-size:10px;color:#64748b;margin:0 0 12px;}
        table{width:100%;border-collapse:collapse;margin-top:8px;}
        th{background:#1e293b;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px;}
        td{padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:9px;}
        tr:nth-child(even) td{background:#f8fafc;}
        tr.ot-detail td{background:#f0f9ff;font-size:8px;}
        .c{text-align:center;}.r{text-align:right;}
        .footer{margin-top:12px;text-align:center;font-size:8px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:6px;}
    </style></head><body>
    <h1>HNIN AKARI NWE - Monthly Attendance Report</h1>
    <p class="sub">' . $month_name . ' ' . $year . ' &nbsp;|&nbsp; Generated: ' . date('d M Y h:i A') . '</p>
    <table>
        <tr><th>Employee Name</th><th>Department</th><th>Position</th><th>Present</th><th>Late</th><th>Half Day</th><th>Absent</th><th>AWOL</th><th>Paid Leave</th><th>Unpaid Leave</th><th>Holiday</th><th>Weekend</th><th>Work Hours</th><th>OT Hours</th><th>OT Pay</th></tr>'
        . $rows_html .
    '</table>
    <div class="footer">This is a computer-generated report. &copy; ' . date('Y') . ' Hnin AKari NWE.</div>
    </body></html>';

    $dompdf->loadHtml($html);
    $dompdf->render();
    $dompdf->stream('Monthly_Attendance_Report_' . $month_name . '_' . $year . '.pdf', ['Attachment' => true]);
    exit;
}

// OT detail fetcher (for expandable rows via AJAX)
if (isset($_GET['ajax_ot']) && $_GET['ajax_ot'] === '1') {
    $emp_id = isset($_GET['emp_id']) ? (int)$_GET['emp_id'] : 0;
    header('Content-Type: application/json');
    if ($emp_id <= 0) { echo json_encode([]); exit; }
    $ot_stmt = $conn->prepare("SELECT ot_date, ot_type, total_hours, status, ot_pay FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
        UNION ALL
        SELECT ot_date, ot_type, total_hours, status, ot_pay FROM overtime_records WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
        ORDER BY ot_date");
    $ot_stmt->bind_param('isssss', $emp_id, $month_start, $month_end, $emp_id, $month_start, $month_end);
    $ot_stmt->execute();
    $ot_rows = $ot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ot_stmt->close();
    echo json_encode($ot_rows);
    exit;
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
        .mar-stat-card { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .mar-stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.06); }
        .sort-btn { cursor: pointer; user-select: none; }
        .sort-btn:hover { opacity: 0.8; }
        .ot-expand-row { display: none; }
        .ot-expand-row.active { display: table-row; }
        .ot-detail-table { width: 100%; font-size: 11px; }
        .ot-detail-table th { background: rgba(255,255,255,0.04); font-size: 10px; padding: 6px 12px; }
        .ot-detail-table td { padding: 6px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); }
    </style>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Monthly Attendance Report"; $page_subtitle = "Employee attendance with overtime overview"; include "../includes/topbar.php"; ?>
        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter">

            <!-- ═══ HEADER ═══ -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center text-xl shadow-lg shadow-blue-500/25 shrink-0">
                        <i class="fa-solid fa-chart-bar"></i>
                    </div>
                    <div>
                        <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">Monthly Attendance Report</h1>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-zinc-400"><?php echo date('F Y', strtotime($actual_month_start ?? $month_start)); ?>
                            <?php if ($total_weeks > 1): ?>
                            &middot; <?php echo htmlspecialchars($weeks[$week_offset]['label']); ?>
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

            <!-- ═══ WEEK PAGINATION ═══ -->
            <?php if ($total_weeks > 1): ?>
            <div class="flex flex-wrap items-center justify-between gap-2 bg-white dark:bg-[#1E293B] rounded-xl p-2 border border-slate-100 dark:border-white/[0.06] shadow-sm">
                <div class="flex flex-wrap gap-1">
                    <?php for ($wi = 0; $wi < $total_weeks; $wi++): ?>
                    <a href="<?php echo build_url(['week' => $wi, 'page' => 1]); ?>" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition <?php echo $wi === $week_offset ? 'bg-blue-500 text-white shadow-sm shadow-blue-500/30' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-white/[0.06]'; ?>">
                        <?php echo $weeks[$wi]['label']; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <div class="flex items-center gap-1">
                    <?php if ($week_offset > 0): ?>
                    <a href="<?php echo build_url(['week' => $week_offset - 1, 'page' => 1]); ?>" class="px-2.5 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] rounded-lg transition-colors">
                        <i class="fa-solid fa-chevron-left"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($week_offset < $total_weeks - 1): ?>
                    <a href="<?php echo build_url(['week' => $week_offset + 1, 'page' => 1]); ?>" class="px-2.5 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] rounded-lg transition-colors">
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══ SUMMARY CARDS ═══ -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3">
                <div class="mar-stat-card bg-white dark:bg-[#1E293B] rounded-xl p-4 border border-slate-100 dark:border-white/[0.06] shadow-sm text-center">
                    <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-indigo-50 dark:bg-indigo-500/10 flex items-center justify-center"><i class="fa-solid fa-users text-indigo-500 dark:text-indigo-400 text-sm"></i></div>
                    <p class="text-xl font-extrabold text-slate-900 dark:text-white"><?php echo (int)$summary['total_employees']; ?></p>
                    <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mt-0.5">Employees</p>
                </div>
                <div class="mar-stat-card bg-white dark:bg-[#1E293B] rounded-xl p-4 border border-slate-100 dark:border-white/[0.06] shadow-sm text-center">
                    <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center"><i class="fa-solid fa-check-circle text-emerald-500 dark:text-emerald-400 text-sm"></i></div>
                    <p class="text-xl font-extrabold text-emerald-600 dark:text-emerald-400"><?php echo (int)$summary['total_present']; ?></p>
                    <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mt-0.5">Present</p>
                </div>
                <div class="mar-stat-card bg-white dark:bg-[#1E293B] rounded-xl p-4 border border-slate-100 dark:border-white/[0.06] shadow-sm text-center">
                    <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-amber-50 dark:bg-amber-500/10 flex items-center justify-center"><i class="fa-solid fa-clock text-amber-500 dark:text-amber-400 text-sm"></i></div>
                    <p class="text-xl font-extrabold text-amber-600 dark:text-amber-400"><?php echo (int)$summary['total_late']; ?></p>
                    <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mt-0.5">Late</p>
                </div>
                <div class="mar-stat-card bg-white dark:bg-[#1E293B] rounded-xl p-4 border border-slate-100 dark:border-white/[0.06] shadow-sm text-center">
                    <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-red-50 dark:bg-red-500/10 flex items-center justify-center"><i class="fa-solid fa-user-xmark text-red-500 dark:text-red-400 text-sm"></i></div>
                    <p class="text-xl font-extrabold text-red-600 dark:text-red-400"><?php echo (int)$summary['total_absent']; ?></p>
                    <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mt-0.5">Absent</p>
                </div>
                <div class="mar-stat-card bg-white dark:bg-[#1E293B] rounded-xl p-4 border border-slate-100 dark:border-white/[0.06] shadow-sm text-center">
                    <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center"><i class="fa-solid fa-calendar-xmark text-blue-500 dark:text-blue-400 text-sm"></i></div>
                    <p class="text-xl font-extrabold text-blue-600 dark:text-blue-400"><?php echo (int)$summary['total_leave']; ?></p>
                    <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mt-0.5">Leave</p>
                </div>
                <div class="mar-stat-card bg-white dark:bg-[#1E293B] rounded-xl p-4 border border-slate-100 dark:border-white/[0.06] shadow-sm text-center">
                    <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-purple-50 dark:bg-purple-500/10 flex items-center justify-center"><i class="fa-solid fa-stopwatch text-purple-500 dark:text-purple-400 text-sm"></i></div>
                    <p class="text-xl font-extrabold text-purple-600 dark:text-purple-400"><?php echo number_format($summary['total_ot_hours'], 1); ?></p>
                    <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mt-0.5">OT Hours</p>
                </div>
                <div class="mar-stat-card bg-white dark:bg-[#1E293B] rounded-xl p-4 border border-slate-100 dark:border-white/[0.06] shadow-sm text-center">
                    <div class="w-8 h-8 mx-auto mb-2 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center"><i class="fa-solid fa-dollar-sign text-teal-500 dark:text-teal-400 text-sm"></i></div>
                    <p class="text-xl font-extrabold text-teal-600 dark:text-teal-400">$<?php echo number_format($summary['total_ot_pay'], 2); ?></p>
                    <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wider mt-0.5">OT Pay</p>
                </div>
            </div>

            <!-- ═══ FILTERS ═══ -->
            <form method="GET" class="bg-white dark:bg-[#1E293B] rounded-2xl p-4 sm:p-5 border border-slate-100 dark:border-white/[0.06] shadow-sm">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_col); ?>">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($sort_dir); ?>">
                <?php if (isset($actual_month_start)): ?>
                <input type="hidden" name="week" value="<?php echo $week_offset; ?>">
                <?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1.5">Month</label>
                        <select name="month" onchange="this.form.week.value='';this.form.submit()" class="w-full bg-slate-50 dark:bg-white/[0.04] border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/30 transition-colors">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1.5">Year</label>
                        <select name="year" onchange="this.form.week.value='';this.form.submit()" class="w-full bg-slate-50 dark:bg-white/[0.04] border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/30 transition-colors">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1.5">Department</label>
                        <select name="department" class="w-full bg-slate-50 dark:bg-white/[0.04] border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/30 transition-colors">
                            <option value="0">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?php echo (int)$d['id']; ?>" <?php echo $selected_dept === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1.5">Search Employee</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Employee name..." class="w-full bg-slate-50 dark:bg-white/[0.04] border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/30 placeholder:text-slate-400 dark:placeholder:text-slate-600 transition-colors">
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="flex-1 px-4 py-2.5 bg-gradient-to-r from-blue-500 to-indigo-500 hover:from-blue-600 hover:to-indigo-600 text-white font-semibold text-sm rounded-xl shadow-sm transition-all hover:scale-[1.02]">
                            <i class="fa-solid fa-magnifying-glass mr-1"></i>Filter
                        </button>
                        <a href="monthly_attendance_report.php" class="px-4 py-2.5 bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] text-slate-600 dark:text-slate-400 font-semibold text-sm rounded-xl transition-colors">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                    </div>
                </div>
            </form>

            <!-- ═══ DATA TABLE ═══ -->
            <div class="bg-white dark:bg-[#1E293B] rounded-2xl border border-slate-100 dark:border-white/[0.06] shadow-sm overflow-hidden">
                <div class="px-4 sm:px-5 py-4 border-b border-slate-100 dark:border-white/[0.06] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <i class="fa-solid fa-table text-blue-500 dark:text-blue-400"></i>
                        Attendance Records
                        <span class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 ml-1">(<?php echo $total_records; ?> employee<?php echo $total_records !== 1 ? 's' : ''; ?>)</span>
                    </h3>
                    <p class="text-[10px] text-slate-400 dark:text-slate-500">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?> &middot; <?php echo $total_records; ?> total records
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-white/[0.02] border-b border-slate-100 dark:border-white/[0.06]">
                                <?php
                                $sort_headers = [
                                    'name' => ['Employee Name', 'name'],
                                    'department_name' => ['Department', 'department_name'],
                                    'present_days' => ['Present', 'present_days'],
                                    'late_days' => ['Late', 'late_days'],
                                    'half_days' => ['Half Day', null],
                                    'absent_days' => ['Absent', 'absent_days'],
                                    'awol_days' => ['AWOL', 'awol_days'],
                                    'paid_leave_days' => ['Paid Leave', null],
                                    'unpaid_leave_days' => ['Unpaid', null],
                                    'holiday_days' => ['Holiday', null],
                                    'weekend_days' => ['Weekend', null],
                                    'total_hours' => ['Hours', null],
                                    'ot_hours' => ['OT Hours', 'ot_hours'],
                                    'ot_pay' => ['OT Pay', 'ot_pay'],
                                ];
                                foreach ($sort_headers as $key => $label_info):
                                    $label = $label_info[0];
                                    $sort_key = $label_info[1];
                                ?>
                                <th class="px-3 sm:px-4 py-3 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider <?php echo $sort_key ? 'sort-btn' : ''; ?>" <?php echo $sort_key ? "onclick=\"window.location.href='" . htmlspecialchars(build_url(['sort' => $sort_key, 'dir' => ($sort_col === $sort_key && $sort_dir === 'ASC') ? 'DESC' : 'ASC', 'page' => 1])) . "'" : ''; ?>>
                                    <?php echo $label; ?>
                                    <?php if ($sort_key && $sort_col === $sort_key): ?>
                                    <i class="fa-solid fa-caret-<?php echo $sort_dir === 'ASC' ? 'up' : 'down'; ?> ml-0.5 text-blue-500"></i>
                                    <?php endif; ?>
                                </th>
                                <?php endforeach; ?>
                                <th class="px-3 sm:px-4 py-3 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider text-center w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50 dark:divide-white/[0.04]">
                            <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="16" class="px-6 py-16 text-center">
                                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 dark:bg-white/[0.04] flex items-center justify-center">
                                        <i class="fa-solid fa-chart-line text-2xl text-slate-300 dark:text-slate-600"></i>
                                    </div>
                                    <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">No records found</p>
                                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Try adjusting your filters or search terms.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($records as $idx => $r):
                                $has_ot = (float)$r['ot_hours'] > 0;
                            ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-white/[0.02] transition-colors">
                                <td class="px-3 sm:px-4 py-3">
                                    <p class="text-xs sm:text-sm font-semibold text-slate-900 dark:text-white"><?php echo htmlspecialchars($r['name']); ?></p>
                                </td>
                                <td class="px-3 sm:px-4 py-3">
                                    <span class="text-xs text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($r['department_name']); ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3">
                                    <span class="text-xs text-slate-500 dark:text-slate-500"><?php echo htmlspecialchars($r['position_name']); ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-xs font-bold text-emerald-600 dark:text-emerald-400"><?php echo $r['present_days']; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-amber-50 dark:bg-amber-500/10 text-xs font-bold text-amber-600 dark:text-amber-400"><?php echo $r['late_days']; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-teal-50 dark:bg-teal-500/10 text-xs font-bold text-teal-600 dark:text-teal-400"><?php echo $r['half_days']; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-red-50 dark:bg-red-500/10 text-xs font-bold text-red-600 dark:text-red-400"><?php echo $r['absent_days']; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-rose-50 dark:bg-rose-500/10 text-xs font-bold text-rose-600 dark:text-rose-400"><?php echo $r['awol_days'] ?? 0; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-xs font-bold text-blue-600 dark:text-blue-400"><?php echo $r['paid_leave_days']; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-orange-50 dark:bg-orange-500/10 text-xs font-bold text-orange-600 dark:text-orange-400"><?php echo $r['unpaid_leave_days']; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-pink-50 dark:bg-pink-500/10 text-xs font-bold text-pink-600 dark:text-pink-400"><?php echo $r['holiday_days']; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-purple-50 dark:bg-purple-500/10 text-xs font-bold text-purple-600 dark:text-purple-400"><?php echo $r['weekend_days']; ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <span class="text-xs font-semibold text-slate-600 dark:text-slate-400 font-mono"><?php echo hours_to_time($r['total_hours']); ?></span>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <?php if ($has_ot): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-purple-50 dark:bg-purple-500/10 text-[10px] font-bold text-purple-600 dark:text-purple-400 font-mono">
                                        <i class="fa-solid fa-stopwatch"></i><?php echo hours_to_time($r['ot_hours']); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-xs text-slate-400 dark:text-slate-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <?php if ($has_ot): ?>
                                    <span class="text-xs font-bold text-teal-600 dark:text-teal-400">$<?php echo number_format($r['ot_pay'], 2); ?></span>
                                    <?php else: ?>
                                    <span class="text-xs text-slate-400 dark:text-slate-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-center">
                                    <?php if ($has_ot): ?>
                                    <button onclick="toggleOTDetails(this, <?php echo (int)$r['id']; ?>)" class="p-1.5 text-slate-400 hover:text-purple-500 dark:hover:text-purple-400 rounded-lg hover:bg-purple-50 dark:hover:bg-purple-500/10 transition-colors" title="View OT Details">
                                        <i class="fa-solid fa-chevron-down text-[10px] ot-chevron"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($has_ot): ?>
                            <tr class="ot-expand-row" id="ot-detail-<?php echo (int)$r['id']; ?>">
                                <td colspan="16" class="px-4 py-3 bg-slate-50 dark:bg-white/[0.015]">
                                    <div class="ml-4 border-l-2 border-purple-300 dark:border-purple-500/30 pl-4">
                                        <p class="text-[10px] font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-2"><i class="fa-solid fa-stopwatch mr-1"></i>Approved Overtime Details</p>
                                        <div class="ot-detail-loading text-xs text-slate-400 py-2">Loading OT details...</div>
                                        <table class="ot-detail-table hidden">
                                            <thead>
                                                <tr>
                                                    <th class="text-left">OT Date</th>
                                                    <th class="text-left">Type</th>
                                                    <th class="text-center">Hours</th>
                                                    <th class="text-center">Status</th>
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

                <!-- ═══ PAGINATION ═══ -->
                <?php if ($total_pages > 1): ?>
                <div class="px-4 sm:px-5 py-4 border-t border-slate-100 dark:border-white/[0.06] flex flex-col sm:flex-row items-center justify-between gap-3">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?> records
                    </p>
                    <div class="flex items-center gap-1.5">
                        <?php if ($page > 1): ?>
                        <a href="<?php echo build_url(['page' => 1]); ?>" class="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] rounded-lg transition-colors">
                            <i class="fa-solid fa-angles-left"></i>
                        </a>
                        <a href="<?php echo build_url(['page' => $page - 1]); ?>" class="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] rounded-lg transition-colors">
                            <i class="fa-solid fa-chevron-left mr-1"></i>Prev
                        </a>
                        <?php endif; ?>

                        <?php
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);
                        for ($p = $start_p; $p <= $end_p; $p++):
                        ?>
                        <a href="<?php echo build_url(['page' => $p]); ?>" class="w-8 h-8 flex items-center justify-center text-xs font-semibold rounded-lg transition-colors <?php echo $p === $page ? 'bg-blue-500 text-white shadow-sm shadow-blue-500/30' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-white/[0.06]'; ?>">
                            <?php echo $p; ?>
                        </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                        <a href="<?php echo build_url(['page' => $page + 1]); ?>" class="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] rounded-lg transition-colors">
                            Next<i class="fa-solid fa-chevron-right ml-1"></i>
                        </a>
                        <a href="<?php echo build_url(['page' => $total_pages]); ?>" class="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] rounded-lg transition-colors">
                            <i class="fa-solid fa-angles-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <script>
    const otCache = {};
    function toggleOTDetails(btn, empId) {
        const row = document.getElementById('ot-detail-' + empId);
        if (!row) return;
        const isOpen = row.classList.contains('active');
        // close all others
        document.querySelectorAll('.ot-expand-row.active').forEach(r => r.classList.remove('active'));
        document.querySelectorAll('.ot-chevron').forEach(c => c.style.transform = '');
        if (isOpen) return;
        row.classList.add('active');
        btn.querySelector('.ot-chevron').style.transform = 'rotate(180deg)';
        if (otCache[empId]) { renderOTDetails(row, otCache[empId]); return; }
        fetch('<?php echo build_url(['ajax_ot' => '1', 'page' => 1]); ?>&ajax_ot=1&emp_id=' + empId)
            .then(r => r.json())
            .then(data => { otCache[empId] = data; renderOTDetails(row, data); })
            .catch(() => { row.querySelector('.ot-detail-loading').textContent = 'Failed to load OT details.'; });
    }
    function renderOTDetails(row, data) {
        const loading = row.querySelector('.ot-detail-loading');
        const table = row.querySelector('.ot-detail-table');
        const tbody = row.querySelector('.ot-detail-body');
        loading.classList.add('hidden');
        if (!data.length) { loading.textContent = 'No OT records found.'; loading.classList.remove('hidden'); return; }
        const typeLabels = { working_day: 'Working Day', weekday: 'Weekday', weekend: 'Weekend', holiday: 'Holiday' };
        const statusColors = { Approved: 'text-emerald-500', Pending: 'text-amber-500', Rejected: 'text-red-500', Cancelled: 'text-slate-400' };
        tbody.innerHTML = data.map(r => `<tr>
            <td class="font-semibold text-slate-700 dark:text-slate-300">${new Date(r.ot_date).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'})}</td>
            <td><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400">${typeLabels[r.ot_type] || r.ot_type || 'N/A'}</span></td>
            <td class="text-center font-semibold text-slate-700 dark:text-slate-300">${parseFloat(r.total_hours).toFixed(1)}h</td>
            <td class="text-center"><span class="font-semibold ${statusColors[r.status] || ''}">${r.status}</span></td>
            <td class="text-right font-bold text-teal-600 dark:text-teal-400">$${parseFloat(r.ot_pay).toFixed(2)}</td>
        </tr>`).join('');
        table.classList.remove('hidden');
    }
    </script>
</body>
</html>
