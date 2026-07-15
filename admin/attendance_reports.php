<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'monthly';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selected_dept = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$selected_emp = isset($_GET['employee']) ? (int)$_GET['employee'] : 0;

$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

// Helper: get department name
function get_dept_name($conn, $id) {
    $r = $conn->query("SELECT department_name FROM departments WHERE id = $id");
    return $r && $row = $r->fetch_assoc() ? $row['department_name'] : 'All Departments';
}

$dept_filter_sql = $selected_dept > 0 ? " AND e.department_id = $selected_dept" : '';
$emp_filter_sql = $selected_emp > 0 ? " AND a.employee_id = $selected_emp" : '';

// Daily report
if ($report_type === 'daily') {
    $title = "Daily Attendance Report - " . date('F d, Y', strtotime($selected_date));
    $records = $conn->query("SELECT a.*, e.name, e.employee_code, d.department_name 
        FROM attendance a JOIN employee e ON a.employee_id = e.id 
        LEFT JOIN departments d ON e.department_id = d.id 
        WHERE a.attendance_date = '$selected_date' $dept_filter_sql $emp_filter_sql
        ORDER BY e.name")->fetch_all(MYSQLI_ASSOC);

    $present_count = 0; $late_count = 0; $absent_count = 0; $leave_count = 0;
    foreach ($records as $r) {
        if ($r['status'] === 'present') $present_count++;
        elseif ($r['status'] === 'late') $late_count++;
        elseif (in_array($r['status'], ['absent', 'awol', 'full_absent'])) $absent_count++;
        elseif (in_array($r['status'], ['paid_leave', 'unpaid_leave', 'leave'])) $leave_count++;
    }
}

// Weekly report
if ($report_type === 'weekly') {
    $week_start = date('Y-m-d', strtotime('monday this week', strtotime($selected_date)));
    $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($selected_date)));
    $title = "Weekly Attendance Report - " . date('M d', strtotime($week_start)) . " to " . date('M d, Y', strtotime($week_end));
    $records = $conn->query("SELECT a.*, e.name, e.employee_code, d.department_name 
        FROM attendance a JOIN employee e ON a.employee_id = e.id 
        LEFT JOIN departments d ON e.department_id = d.id 
        WHERE a.attendance_date BETWEEN '$week_start' AND '$week_end' $dept_filter_sql $emp_filter_sql
        ORDER BY a.attendance_date, e.name")->fetch_all(MYSQLI_ASSOC);
}

// Monthly report
if ($report_type === 'monthly') {
    $title = "Monthly Attendance Report - " . date('F Y', strtotime($month_start));
    $records = $conn->query("SELECT a.*, e.name, e.employee_code, d.department_name 
        FROM attendance a JOIN employee e ON a.employee_id = e.id 
        LEFT JOIN departments d ON e.department_id = d.id 
        WHERE a.attendance_date BETWEEN '$month_start' AND '$month_end' $dept_filter_sql $emp_filter_sql
        ORDER BY a.attendance_date, e.name")->fetch_all(MYSQLI_ASSOC);

    // Monthly summary
    $monthly_summary = $conn->query("SELECT 
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) as half_day,
        SUM(CASE WHEN a.status IN ('paid_leave', 'leave') THEN 1 ELSE 0 END) as paid_leave,
        SUM(CASE WHEN a.status = 'unpaid_leave' THEN 1 ELSE 0 END) as unpaid_leave,
        SUM(CASE WHEN a.status IN ('absent', 'awol', 'full_absent') THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN a.status = 'public_holiday' THEN 1 ELSE 0 END) as holiday,
        SUM(CASE WHEN a.status = 'weekend' THEN 1 ELSE 0 END) as weekend,
        COALESCE(SUM(a.total_working_hours), 0) as total_hours
        FROM attendance a JOIN employee e ON a.employee_id = e.id 
        WHERE a.attendance_date BETWEEN '$month_start' AND '$month_end' $dept_filter_sql $emp_filter_sql")->fetch_assoc();
}

// Yearly report
if ($report_type === 'yearly') {
    $title = "Yearly Attendance Report - $selected_year";
    $yearly_data = [];
    for ($m = 1; $m <= 12; $m++) {
        $ms = sprintf('%04d-%02d-01', $selected_year, $m);
        $me = date('Y-m-t', strtotime($ms));
        $row = $conn->query("SELECT 
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN a.status IN ('absent', 'awol', 'full_absent') THEN 1 ELSE 0 END) as absent
            FROM attendance a JOIN employee e ON a.employee_id = e.id 
            WHERE a.attendance_date BETWEEN '$ms' AND '$me' $dept_filter_sql $emp_filter_sql")->fetch_assoc();
        $yearly_data[$m] = $row ?: ['present' => 0, 'late' => 0, 'absent' => 0];
    }
}

// Department report
if ($report_type === 'department') {
    $title = "Department Attendance Report" . ($selected_dept > 0 ? " - " . get_dept_name($conn, $selected_dept) : "");
    $records = $conn->query("SELECT 
        d.department_name, COUNT(a.id) as total,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN a.status IN ('absent', 'awol', 'full_absent') THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) as half_day
        FROM attendance a 
        JOIN employee e ON a.employee_id = e.id 
        JOIN departments d ON e.department_id = d.id 
        WHERE a.attendance_date BETWEEN '$month_start' AND '$month_end'
        GROUP BY d.department_name ORDER BY d.department_name")->fetch_all(MYSQLI_ASSOC);
}

// Employee report
if ($report_type === 'employee' && $selected_emp > 0) {
    $emp_info = $conn->query("SELECT e.name, e.employee_code, d.department_name FROM employee e LEFT JOIN departments d ON e.department_id = d.id WHERE e.id = $selected_emp")->fetch_assoc();
    $title = "Employee Attendance Report - " . ($emp_info['name'] ?? 'Unknown');
    $records = $conn->query("SELECT a.* FROM attendance a 
        WHERE a.employee_id = $selected_emp AND a.attendance_date BETWEEN '$month_start' AND '$month_end'
        ORDER BY a.attendance_date")->fetch_all(MYSQLI_ASSOC);
}

$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);
$employees = $conn->query("SELECT id, name, employee_code FROM employee WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance Reports</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php include "../includes/topbar.php"; ?>
        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter">

            <!-- Header -->
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 text-white flex items-center justify-center text-xl shadow-lg shadow-cyan-500/25">
                        <i class="fa-solid fa-chart-pie"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-extrabold text-white tracking-tight">Attendance Reports</h1>
                        <p class="text-sm text-zinc-400">Comprehensive attendance analytics and reports</p>
                    </div>
                </div>
            </div>

            <!-- Report Type Selector -->
            <form method="GET" class="glass-strong rounded-2xl p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Report Type</label>
                        <select name="report_type" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-cyan-500/30">
                            <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Report</option>
                            <option value="weekly" <?php echo $report_type === 'weekly' ? 'selected' : ''; ?>>Weekly Report</option>
                            <option value="monthly" <?php echo $report_type === 'monthly' ? 'selected' : ''; ?>>Monthly Report</option>
                            <option value="yearly" <?php echo $report_type === 'yearly' ? 'selected' : ''; ?>>Yearly Report</option>
                            <option value="department" <?php echo $report_type === 'department' ? 'selected' : ''; ?>>Department Report</option>
                            <option value="employee" <?php echo $report_type === 'employee' ? 'selected' : ''; ?>>Employee Report</option>
                        </select>
                    </div>
                    <?php if ($report_type === 'daily' || $report_type === 'weekly'): ?>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Date</label>
                        <input type="date" name="date" value="<?php echo $selected_date; ?>" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-cyan-500/30">
                    </div>
                    <?php endif; ?>
                    <?php if (in_array($report_type, ['monthly', 'department', 'employee'])): ?>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Month</label>
                        <select name="month" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-cyan-500/30">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Year</label>
                        <select name="year" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-cyan-500/30">
                            <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if ($report_type === 'yearly'): ?>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Year</label>
                        <select name="year" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-cyan-500/30">
                            <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Department</label>
                        <select name="department" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-cyan-500/30">
                            <option value="0">All</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $selected_dept === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Employee</label>
                        <select name="employee" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-cyan-500/30">
                            <option value="0">All</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo $selected_emp === (int)$e['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['name'] . ' (' . $e['employee_code'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white font-semibold text-sm rounded-xl shadow-lg shadow-cyan-500/25 transition-all hover:scale-105">
                        <i class="fa-solid fa-chart-simple mr-1"></i> Generate Report
                    </button>
                </div>
            </form>

            <!-- Report Content -->
            <div class="glass-strong rounded-2xl p-6">
                <h2 class="text-lg font-bold text-white mb-6"><?php echo $title; ?></h2>

                <!-- Monthly Summary Charts -->
                <?php if ($report_type === 'monthly' && isset($monthly_summary)): ?>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-300 mb-3">Status Distribution</h3>
                        <canvas id="statusChart" height="200"></canvas>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-300 mb-3">Attendance Breakdown</h3>
                        <canvas id="breakdownChart" height="200"></canvas>
                    </div>
                </div>

                <!-- Summary Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                    <div class="bg-emerald-500/10 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-emerald-400"><?php echo (int)$monthly_summary['present']; ?></p>
                        <p class="text-xs text-zinc-400">Present</p>
                    </div>
                    <div class="bg-amber-500/10 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-amber-400"><?php echo (int)$monthly_summary['late']; ?></p>
                        <p class="text-xs text-zinc-400">Late</p>
                    </div>
                    <div class="bg-teal-500/10 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-teal-400"><?php echo (int)$monthly_summary['half_day']; ?></p>
                        <p class="text-xs text-zinc-400">Half Day</p>
                    </div>
                    <div class="bg-red-500/10 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-red-400"><?php echo (int)$monthly_summary['absent']; ?></p>
                        <p class="text-xs text-zinc-400">Absent</p>
                    </div>
                    <div class="bg-sky-500/10 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-sky-400"><?php echo (int)$monthly_summary['paid_leave']; ?></p>
                        <p class="text-xs text-zinc-400">Paid Leave</p>
                    </div>
                    <div class="bg-orange-500/10 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-orange-400"><?php echo (int)$monthly_summary['unpaid_leave']; ?></p>
                        <p class="text-xs text-zinc-400">Unpaid Leave</p>
                    </div>
                    <div class="bg-purple-500/10 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-purple-400"><?php echo number_format($monthly_summary['total_hours'], 1); ?></p>
                        <p class="text-xs text-zinc-400">Total Hours</p>
                    </div>
                    <div class="bg-pink-500/10 rounded-xl p-4 text-center">
                        <p class="text-2xl font-bold text-pink-400"><?php echo (int)$monthly_summary['holiday']; ?></p>
                        <p class="text-xs text-zinc-400">Holidays</p>
                    </div>
                </div>

                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    new Chart(document.getElementById('statusChart'), {
                        type: 'doughnut',
                        data: {
                            labels: ['Present', 'Late', 'Half Day', 'Absent', 'Paid Leave', 'Unpaid Leave'],
                            datasets: [{
                                data: [<?php echo (int)$monthly_summary['present']; ?>, <?php echo (int)$monthly_summary['late']; ?>, <?php echo (int)$monthly_summary['half_day']; ?>, <?php echo (int)$monthly_summary['absent']; ?>, <?php echo (int)$monthly_summary['paid_leave']; ?>, <?php echo (int)$monthly_summary['unpaid_leave']; ?>],
                                backgroundColor: ['#10B981', '#F59E0B', '#14B8A6', '#EF4444', '#0EA5E9', '#F97316'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { position: 'bottom', labels: { color: getChartColors().text } }
                            }
                        }
                    });
                    new Chart(document.getElementById('breakdownChart'), {
                        type: 'bar',
                        data: {
                            labels: ['Present', 'Late', 'Half Day', 'Absent', 'Paid Leave', 'Unpaid Leave'],
                            datasets: [{
                                label: 'Days',
                                data: [<?php echo (int)$monthly_summary['present']; ?>, <?php echo (int)$monthly_summary['late']; ?>, <?php echo (int)$monthly_summary['half_day']; ?>, <?php echo (int)$monthly_summary['absent']; ?>, <?php echo (int)$monthly_summary['paid_leave']; ?>, <?php echo (int)$monthly_summary['unpaid_leave']; ?>],
                                backgroundColor: ['rgba(16,185,129,0.6)', 'rgba(245,158,11,0.6)', 'rgba(20,184,166,0.6)', 'rgba(239,68,68,0.6)', 'rgba(14,165,233,0.6)', 'rgba(249,115,22,0.6)'],
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: { beginAtZero: true, grid: { color: getChartColors().grid }, ticks: { color: getChartColors().text } },
                                x: { grid: { display: false }, ticks: { color: getChartColors().text } }
                            }
                        }
                    });
                });
                </script>
                <?php endif; ?>

                <!-- Yearly Chart -->
                <?php if ($report_type === 'yearly' && isset($yearly_data)): ?>
                <div class="mb-6">
                    <canvas id="yearlyChart" height="250"></canvas>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    new Chart(document.getElementById('yearlyChart'), {
                        type: 'bar',
                        data: {
                            labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                            datasets: [
                                { label: 'Present', data: [<?php foreach(range(1,12) as $m): ?><?php echo (int)$yearly_data[$m]['present']; ?>,<?php endforeach; ?>], backgroundColor: 'rgba(16,185,129,0.7)', borderRadius: 4 },
                                { label: 'Late', data: [<?php foreach(range(1,12) as $m): ?><?php echo (int)$yearly_data[$m]['late']; ?>,<?php endforeach; ?>], backgroundColor: 'rgba(245,158,11,0.7)', borderRadius: 4 },
                                { label: 'Absent', data: [<?php foreach(range(1,12) as $m): ?><?php echo (int)$yearly_data[$m]['absent']; ?>,<?php endforeach; ?>], backgroundColor: 'rgba(239,68,68,0.7)', borderRadius: 4 }
                            ]
                        },
                        options: {
                            responsive: true,
                            plugins: { legend: { position: 'bottom', labels: { color: getChartColors().text } } },
                            scales: {
                                y: { beginAtZero: true, grid: { color: getChartColors().grid }, ticks: { color: getChartColors().text } },
                                x: { grid: { display: false }, ticks: { color: getChartColors().text } }
                            }
                        }
                    });
                });
                </script>
                <?php endif; ?>

                <!-- Department Report Chart -->
                <?php if ($report_type === 'department' && !empty($records)): ?>
                <div class="mb-6">
                    <canvas id="deptChart" height="250"></canvas>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    new Chart(document.getElementById('deptChart'), {
                        type: 'bar',
                        data: {
                            labels: [<?php foreach ($records as $r): ?>'<?php echo htmlspecialchars($r['department_name'], ENT_QUOTES); ?>',<?php endforeach; ?>],
                            datasets: [
                                { label: 'Present', data: [<?php foreach ($records as $r): ?><?php echo (int)$r['present']; ?>,<?php endforeach; ?>], backgroundColor: 'rgba(16,185,129,0.7)' },
                                { label: 'Late', data: [<?php foreach ($records as $r): ?><?php echo (int)$r['late']; ?>,<?php endforeach; ?>], backgroundColor: 'rgba(245,158,11,0.7)' },
                                { label: 'Absent', data: [<?php foreach ($records as $r): ?><?php echo (int)$r['absent']; ?>,<?php endforeach; ?>], backgroundColor: 'rgba(239,68,68,0.7)' }
                            ]
                        },
                        options: {
                            responsive: true,
                            plugins: { legend: { position: 'bottom', labels: { color: getChartColors().text } } },
                            scales: {
                                y: { beginAtZero: true, grid: { color: getChartColors().grid }, ticks: { color: getChartColors().text } },
                                x: { grid: { display: false }, ticks: { color: getChartColors().text } }
                            }
                        }
                    });
                });
                </script>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider bg-white/[0.03]">
                            <tr>
                                <th class="px-4 py-3">Department</th>
                                <th class="px-4 py-3 text-center">Total</th>
                                <th class="px-4 py-3 text-center">Present</th>
                                <th class="px-4 py-3 text-center">Late</th>
                                <th class="px-4 py-3 text-center">Absent</th>
                                <th class="px-4 py-3 text-center">Half Day</th>
                                <th class="px-4 py-3 text-center">Rate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php foreach ($records as $r):
                                $rate = (int)$r['total'] > 0 ? round(((int)$r['present'] + (int)$r['late']) / (int)$r['total'] * 100, 1) : 0;
                            ?>
                            <tr class="hover:bg-white/[0.02]">
                                <td class="px-4 py-3 font-semibold text-white"><?php echo htmlspecialchars($r['department_name']); ?></td>
                                <td class="px-4 py-3 text-center text-zinc-300"><?php echo $r['total']; ?></td>
                                <td class="px-4 py-3 text-center text-emerald-400"><?php echo $r['present']; ?></td>
                                <td class="px-4 py-3 text-center text-amber-400"><?php echo $r['late']; ?></td>
                                <td class="px-4 py-3 text-center text-red-400"><?php echo $r['absent']; ?></td>
                                <td class="px-4 py-3 text-center text-teal-400"><?php echo $r['half_day']; ?></td>
                                <td class="px-4 py-3 text-center"><span class="font-semibold <?php echo $rate >= 80 ? 'text-emerald-400' : ($rate >= 50 ? 'text-amber-400' : 'text-red-400'); ?>"><?php echo $rate; ?>%</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Employee Report -->
                <?php if ($report_type === 'employee' && $selected_emp > 0): ?>
                <div class="mb-4 flex items-center gap-3 bg-white/[0.03] rounded-xl p-4">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-400 flex items-center justify-center">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-white"><?php echo htmlspecialchars($emp_info['name'] ?? ''); ?></p>
                        <p class="text-xs text-zinc-400"><?php echo htmlspecialchars($emp_info['employee_code'] ?? ''); ?> · <?php echo htmlspecialchars($emp_info['department_name'] ?? 'N/A'); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Generic Records Table -->
                <?php if (isset($records) && !empty($records) && !in_array($report_type, ['department'])): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider bg-white/[0.03]">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Employee</th>
                                <?php if ($report_type !== 'employee'): ?><th class="px-4 py-3">Department</th><?php endif; ?>
                                <th class="px-4 py-3 text-center">In</th>
                                <th class="px-4 py-3 text-center">Out</th>
                                <th class="px-4 py-3 text-center">Hours</th>
                                <th class="px-4 py-3 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php foreach ($records as $r): ?>
                            <tr class="hover:bg-white/[0.02]">
                                <td class="px-4 py-3 text-white whitespace-nowrap"><?php echo date('M d, Y', strtotime($r['attendance_date'])); ?></td>
                                <td class="px-4 py-3">
                                    <span class="font-semibold text-white text-xs"><?php echo htmlspecialchars($r['name'] ?? $r['employee_name']); ?></span>
                                    <span class="text-[10px] text-zinc-500 ml-1"><?php echo htmlspecialchars($r['employee_code']); ?></span>
                                </td>
                                <?php if ($report_type !== 'employee'): ?>
                                <td class="px-4 py-3 text-zinc-400 text-xs"><?php echo htmlspecialchars($r['department_name'] ?? ''); ?></td>
                                <?php endif; ?>
                                <td class="px-4 py-3 text-center font-mono text-xs <?php echo $r['check_in'] ? ($r['is_late'] ? 'text-amber-400' : 'text-emerald-400') : 'text-zinc-600'; ?>">
                                    <?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '—'; ?>
                                </td>
                                <td class="px-4 py-3 text-center font-mono text-xs text-zinc-300">
                                    <?php echo $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '—'; ?>
                                </td>
                                <td class="px-4 py-3 text-center text-xs text-zinc-300">
                                    <?php echo $r['total_working_hours'] ? number_format($r['total_working_hours'], 1) . 'h' : '—'; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold <?php echo get_attendance_status_badge_class($r['status']); ?>">
                                        <?php echo get_attendance_status_label($r['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php elseif (empty($records) && !in_array($report_type, ['monthly', 'yearly', 'department'])): ?>
                <div class="p-12 text-center text-zinc-500">
                    <i class="fa-solid fa-chart-line text-2xl text-zinc-600 block mb-2"></i>
                    No records found for this period.
                </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</body>
</html>
