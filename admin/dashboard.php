<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
require_once '../config/db.php';
require_once '../config/helpers.php';

// ── Workforce ──
$workforce = $conn->query("SELECT COUNT(*) as cnt FROM employee WHERE status = 'active'")->fetch_assoc()['cnt'];

// ── Today's Attendance ──
$today_checkins = $conn->query("SELECT COUNT(*) as cnt FROM attendance WHERE attendance_date = CURDATE() AND check_in IS NOT NULL")->fetch_assoc()['cnt'];
$today_absent = max(0, $workforce - $today_checkins);

// ── Pending Leaves ──
$pending_leaves = $conn->query("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'Pending'")->fetch_assoc()['cnt'];

// ── Pending OT ──
$has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;
if ($has_source) {
    $pending_ot = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending' AND (source IS NULL OR source = 'employee_request')")->fetch_assoc()['cnt'];
} else {
    $pending_ot = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending'")->fetch_assoc()['cnt'];
}
$pending_actions = $pending_leaves + $pending_ot;

// ── Payroll ──
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$gross_total = $conn->query("SELECT COALESCE(SUM(gross_salary), 0) as t FROM payrolls WHERE payroll_month = MONTH(CURDATE()) AND payroll_year = YEAR(CURDATE())")->fetch_assoc()['t'];
$net_total = $conn->query("SELECT COALESCE(SUM(net_salary), 0) as t FROM payrolls WHERE payroll_month = MONTH(CURDATE()) AND payroll_year = YEAR(CURDATE())")->fetch_assoc()['t'];

// ── Monthly Payroll Data ──
$monthly_payroll_data = [];
for ($m = 1; $m <= 12; $m++) {
    $res = $conn->query("SELECT COALESCE(SUM(net_salary), 0) as total FROM payrolls WHERE payroll_month = $m AND payroll_year = " . date('Y'));
    $monthly_payroll_data[] = (float)$res->fetch_assoc()['total'];
}

// ── Department Distribution ──
$departments = $conn->query("SELECT d.department_name, COUNT(e.id) as emp_count FROM departments d LEFT JOIN employee e ON e.department_id = d.id GROUP BY d.id ORDER BY emp_count DESC")->fetch_all(MYSQLI_ASSOC);
$total_dept_emps = array_sum(array_column($departments, 'emp_count'));
$dept_labels = []; $dept_counts = [];
$dept_colors = ['#1E3A8A', '#4F46E5', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#06B6D4', '#F97316'];
foreach ($departments as $dept) { $dept_labels[] = $dept['department_name'] ?? 'Unassigned'; $dept_counts[] = (int)$dept['emp_count']; }

// ── Recent Attendance ──
$recent_att = $conn->query("SELECT e.name, e.employee_code, a.attendance_date, a.check_in, a.check_out, a.status, a.total_working_hours FROM attendance a JOIN employee e ON a.employee_id = e.id ORDER BY a.attendance_date DESC, a.check_in DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// ── Recent Leave Requests ──
$recent_leaves = $conn->query("SELECT lr.*, e.name as employee_name, e.employee_code FROM leave_requests lr JOIN employee e ON lr.employee_id = e.id ORDER BY lr.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// ── Attendance Rate ──
$monthly_att = $conn->query("SELECT COUNT(*) as total_records, SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_count FROM attendance WHERE attendance_date BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();
$attendance_rate = $monthly_att['total_records'] > 0 ? round(($monthly_att['present_count'] / $monthly_att['total_records']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Admin Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php include "../includes/topbar.php"; ?>

        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter">

            <!-- ═══ Welcome Banner ═══ -->
            <div class="relative overflow-hidden bg-gradient-to-r from-blue-700 via-indigo-600 to-blue-500 rounded-2xl p-6 lg:p-8 animate-fade-in-up card-inner-glow">
                <div class="absolute inset-0 opacity-10">
                    <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                    <div class="absolute bottom-0 left-0 w-48 h-48 bg-white rounded-full blur-3xl translate-y-1/2 -translate-x-1/4"></div>
                </div>
                <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <h2 class="text-2xl lg:text-3xl font-extrabold text-white tracking-tight">
                            <?php
                            $hour = (int)date('H');
                            if ($hour < 12) echo 'Good Morning';
                            elseif ($hour < 17) echo 'Good Afternoon';
                            else echo 'Good Evening';
                            ?>, <?php echo htmlspecialchars($admin_name); ?>!
                        </h2>
                        <p class="text-sm text-white/70 mt-1">Here's what's happening across your organization today.</p>
                        <div class="flex items-center gap-4 mt-3">
                            <span class="inline-flex items-center gap-1.5 text-xs text-white/80"><span class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse"></span><?php echo $workforce; ?> Active Employees</span>
                            <span class="inline-flex items-center gap-1.5 text-xs text-white/80"><span class="w-2 h-2 bg-amber-400 rounded-full"></span><?php echo $pending_actions; ?> Pending Actions</span>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <a href="employee.php" class="bg-white/20 hover:bg-white/30 text-white font-semibold text-sm px-5 py-2.5 rounded-xl border border-white/20 transition-all duration-200 text-center backdrop-blur-sm hover:scale-105 active:scale-95 flex items-center gap-2">
                            <i class="fa-solid fa-users"></i> View Employees
                        </a>
                        <a href="reports.php" class="bg-white text-blue-700 font-semibold text-sm px-5 py-2.5 rounded-xl transition-all duration-200 text-center hover:shadow-lg hover:shadow-white/20 hover:scale-105 active:scale-95 flex items-center gap-2">
                            <i class="fa-solid fa-chart-line"></i> View Reports
                        </a>
                    </div>
                </div>
            </div>

            <!-- ═══ Stat Cards Row 1 ═══ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <!-- Total Employees -->
                <div class="group glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-1">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-users mr-1 text-blue-400"></i>Total Employees</span>
                            <div class="text-3xl font-extrabold text-gradient mt-1"><span class="counter-value" data-counter="<?php echo $workforce; ?>">0</span></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-users"></i></div>
                    </div>
                    <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: 100%"></div></div>
                    <span class="text-xs text-emerald-400 font-medium mt-2 inline-block"><i class="fa-solid fa-circle-check mr-1"></i>All active</span>
                </div>

                <!-- Present Today -->
                <div class="group glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-2">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-calendar-check mr-1 text-emerald-400"></i>Present Today</span>
                            <div class="text-3xl font-extrabold text-gradient-emerald mt-1"><span class="counter-value" data-counter="<?php echo $today_checkins; ?>">0</span> <span class="text-base font-medium text-zinc-500">/ <?php echo $workforce; ?></span></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-user-check"></i></div>
                    </div>
                    <?php $att_pct = $workforce > 0 ? round(($today_checkins / $workforce) * 100) : 0; ?>
                    <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: <?php echo $att_pct; ?>%"></div></div>
                    <span class="text-xs text-zinc-400 font-medium mt-2 inline-block"><?php echo $att_pct; ?>% attendance rate</span>
                </div>

                <!-- Absent Today -->
                <div class="group glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-3">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-user-xmark mr-1 text-rose-400"></i>Absent Today</span>
                            <div class="text-3xl font-extrabold text-white mt-1"><span class="counter-value" data-counter="<?php echo $today_absent; ?>">0</span></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-500/20 to-red-500/20 text-rose-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-user-xmark"></i></div>
                    </div>
                    <?php if ($today_absent > 0): ?>
                    <span class="text-xs text-rose-400 font-medium mt-2 inline-block"><i class="fa-solid fa-triangle-exclamation mr-1"></i><?php echo $today_absent; ?> missing</span>
                    <?php else: ?>
                    <span class="text-xs text-emerald-400 font-medium mt-2 inline-block"><i class="fa-solid fa-circle-check mr-1"></i>Full attendance</span>
                    <?php endif; ?>
                </div>

                <!-- Pending Leaves -->
                <div class="group glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-envelope mr-1 text-amber-400"></i>Pending Leaves</span>
                            <div class="text-3xl font-extrabold text-white mt-1"><span class="counter-value" data-counter="<?php echo $pending_leaves; ?>">0</span></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/20 text-amber-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-envelope"></i></div>
                    </div>
                    <?php if ($pending_leaves > 0): ?>
                    <a href="leaveApproval.php" class="text-xs text-amber-400 hover:text-amber-300 font-medium mt-2 inline-block transition-colors"><i class="fa-solid fa-arrow-right mr-1"></i>Review now</a>
                    <?php else: ?>
                    <span class="text-xs text-zinc-500 font-medium mt-2 inline-block">All caught up</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══ Stat Cards Row 2 ═══ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <!-- Monthly Payroll -->
                <div class="group glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-file-invoice-dollar mr-1 text-cyan-400"></i>Monthly Payroll</span>
                            <div class="text-3xl font-extrabold text-gradient-amber mt-1"><?php echo $gross_total >= 1000000 ? '$' . number_format($gross_total / 1000000, 1) . 'M' : '$' . number_format($gross_total); ?></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-500/20 text-cyan-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    </div>
                    <span class="text-xs text-zinc-400 font-medium mt-2 inline-block"><i class="fa-solid fa-calendar mr-1"></i><?php echo date('F Y'); ?></span>
                </div>

                <!-- Net Salary Expense -->
                <div class="group glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-6">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-money-bill-wave mr-1 text-emerald-400"></i>Net Expense</span>
                            <div class="text-3xl font-extrabold text-gradient-emerald mt-1"><?php echo $net_total >= 1000000 ? '$' . number_format($net_total / 1000000, 1) . 'M' : '$' . number_format($net_total); ?></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500/20 to-green-500/20 text-emerald-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-money-bill-wave"></i></div>
                    </div>
                    <span class="text-xs text-emerald-400 font-medium mt-2 inline-block">After deductions</span>
                </div>

                <!-- Attendance Rate -->
                <div class="group glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-7">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-chart-line mr-1 text-blue-400"></i>Attendance Rate</span>
                            <div class="text-3xl font-extrabold text-gradient mt-1"><?php echo $attendance_rate; ?>%</div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-chart-simple"></i></div>
                    </div>
                    <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: <?php echo $attendance_rate; ?>%"></div></div>
                </div>

                <!-- Pending Approvals -->
                <div class="group glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-8">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-bell mr-1 text-rose-400"></i>Pending Actions</span>
                            <div class="text-3xl font-extrabold text-white mt-1"><?php echo $pending_actions; ?></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-500/20 to-pink-500/20 text-rose-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-bell"></i></div>
                    </div>
                    <span class="text-xs mt-2 inline-flex items-center gap-2"><span class="badge badge-amber"><?php echo $pending_leaves; ?> leave</span><span class="badge badge-rose"><?php echo $pending_ot; ?> OT</span></span>
                </div>
            </div>

            <!-- ═══ Charts Row ═══ -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <!-- Payroll Trend -->
                <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-1">
                    <h3 class="font-bold text-white text-base mb-4 border-b border-white/[0.06] pb-3 flex items-center justify-between">
                        <span><i class="fa-solid fa-chart-line text-blue-400 mr-2"></i>Payroll Trend (<?php echo date('Y'); ?>)</span>
                        <span class="badge badge-slate text-[9px]">Annual</span>
                    </h3>
                    <div class="relative h-48 sm:h-56 lg:h-64">
                        <canvas id="payrollChart"></canvas>
                    </div>
                </div>

                <!-- Department Distribution -->
                <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-2">
                    <h3 class="font-bold text-white text-base mb-4 border-b border-white/[0.06] pb-3"><i class="fa-solid fa-chart-pie text-blue-400 mr-2"></i>Employee by Department</h3>
                    <div class="relative h-48 sm:h-56 lg:h-64">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- ═══ Quick Actions ═══ -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 animate-fade-in-up stagger-1">
                <a href="insert1.php" class="group glass-strong rounded-2xl p-5 card-hover text-center">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-user-plus"></i></div>
                    <span class="text-xs font-bold text-zinc-300 mt-3 block">Add Employee</span>
                </a>
                <a href="dailyattendance.php" class="group glass-strong rounded-2xl p-5 card-hover text-center">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-calendar-check"></i></div>
                    <span class="text-xs font-bold text-zinc-300 mt-3 block">Attendance</span>
                </a>
                <a href="attendance_summary.php" class="group glass-strong rounded-2xl p-5 card-hover text-center">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-500/20 text-cyan-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-chart-simple"></i></div>
                    <span class="text-xs font-bold text-zinc-300 mt-3 block">Att. Summary</span>
                </a>
                <a href="leaveApproval.php" class="group glass-strong rounded-2xl p-5 card-hover text-center relative">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/20 text-amber-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-check-double"></i></div>
                    <span class="text-xs font-bold text-zinc-300 mt-3 block">Approve Leave</span>
                    <?php if ($pending_leaves > 0): ?>
                    <span class="absolute top-3 right-3 w-5 h-5 bg-rose-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center"><?php echo $pending_leaves; ?></span>
                    <?php endif; ?>
                </a>
                <a href="payroll.php" class="group glass-strong rounded-2xl p-5 card-hover text-center">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-500/20 text-cyan-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-calculator"></i></div>
                    <span class="text-xs font-bold text-zinc-300 mt-3 block">Run Payroll</span>
                </a>
                <a href="reports.php" class="group glass-strong rounded-2xl p-5 card-hover text-center">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-rose-500/20 to-pink-500/20 text-rose-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-file-lines"></i></div>
                    <span class="text-xs font-bold text-zinc-300 mt-3 block">View Reports</span>
                </a>
            </div>

            <!-- ═══ Tables Row ═══ -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <!-- Recent Attendance -->
                <div class="glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in-up stagger-1">
                    <div class="p-5 border-b border-white/[0.06] flex items-center justify-between">
                        <h3 class="font-bold text-white"><i class="fa-solid fa-clock-rotate-left text-blue-400 mr-2"></i>Recent Attendance</h3>
                        <a href="dailyattendance.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>View All</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="px-5 py-3">Employee</th>
                                    <th class="px-5 py-3">Date</th>
                                    <th class="px-5 py-3">In</th>
                                    <th class="px-5 py-3">Out</th>
                                    <th class="px-5 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04]">
                                <?php if (empty($recent_att)): ?>
                                <tr><td colspan="5" class="px-5 py-8 text-center text-zinc-500">
                                    <i class="fa-regular fa-calendar-xmark text-2xl text-zinc-600 block mb-2"></i>No attendance records today.
                                </td></tr>
                                <?php else: foreach ($recent_att as $att): ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-white text-xs"><?php echo htmlspecialchars($att['name']); ?></div>
                                        <div class="text-[10px] text-zinc-500"><?php echo htmlspecialchars($att['employee_code']); ?></div>
                                    </td>
                                    <td class="px-5 py-3 text-zinc-400 text-xs"><?php echo date('M d', strtotime($att['attendance_date'])); ?></td>
                                    <td class="px-5 py-3 text-xs font-mono text-emerald-400"><?php echo $att['check_in'] ? date('h:i A', strtotime($att['check_in'])) : '-'; ?></td>
                                    <td class="px-5 py-3 text-xs font-mono text-rose-400"><?php echo $att['check_out'] ? date('h:i A', strtotime($att['check_out'])) : '-'; ?></td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-[10px] font-semibold <?php echo get_attendance_status_badge_class($att['status']); ?>"><?php echo get_attendance_status_label($att['status']); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Leave Requests -->
                <div class="glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in-up stagger-2">
                    <div class="p-5 border-b border-white/[0.06] flex items-center justify-between">
                        <h3 class="font-bold text-white"><i class="fa-solid fa-envelope-open-text text-blue-400 mr-2"></i>Recent Leave Requests</h3>
                        <a href="leaveApproval.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>View All</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="px-5 py-3">Employee</th>
                                    <th class="px-5 py-3">Type</th>
                                    <th class="px-5 py-3">Dates</th>
                                    <th class="px-5 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04]">
                                <?php if (empty($recent_leaves)): ?>
                                <tr><td colspan="4" class="px-5 py-8 text-center text-zinc-500">
                                    <i class="fa-regular fa-envelope-open text-2xl text-zinc-600 block mb-2"></i>No leave requests yet.
                                </td></tr>
                                <?php else: foreach ($recent_leaves as $lr): ?>
                                <tr class="hover:bg-white/[0.02] transition-colors <?php echo $lr['status'] == 'Pending' ? 'bg-amber-500/5' : ''; ?>">
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-white text-xs"><?php echo htmlspecialchars($lr['employee_name']); ?></div>
                                        <div class="text-[10px] text-zinc-500"><?php echo htmlspecialchars($lr['employee_code']); ?></div>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-zinc-300"><?php echo htmlspecialchars($lr['leave_type']); ?></td>
                                    <td class="px-5 py-3 text-xs text-zinc-400"><?php echo date('M d', strtotime($lr['start_date'])); ?> - <?php echo date('M d', strtotime($lr['end_date'])); ?></td>
                                    <td class="px-5 py-3">
                                        <?php
                                        $sc = match($lr['status']) {
                                            'Approved' => 'bg-emerald-500/20 text-emerald-400',
                                            'Rejected' => 'bg-red-500/20 text-red-400',
                                            default => 'bg-amber-500/20 text-amber-400'
                                        };
                                        ?>
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-[10px] font-semibold <?php echo $sc; ?>"><?php echo $lr['status']; ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══ Department Density ═══ -->
            <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-1">
                <h3 class="font-bold text-white text-base mb-4 border-b border-white/[0.06] pb-3"><i class="fa-solid fa-building text-blue-400 mr-2"></i>Department Distribution</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($departments as $dept):
                        $pct = $total_dept_emps > 0 ? round(($dept['emp_count'] / $total_dept_emps) * 100) : 0;
                    ?>
                    <div class="space-y-1.5">
                        <div class="flex justify-between text-xs font-semibold">
                            <span class="text-zinc-300"><?php echo htmlspecialchars($dept['department_name'] ?? 'Unassigned'); ?></span>
                            <span class="text-zinc-500"><?php echo $dept['emp_count']; ?> <span class="text-zinc-600 font-normal">/ <?php echo $pct; ?>%</span></span>
                        </div>
                        <div class="progress-bar"><div class="progress-bar-fill" style="width: <?php echo $pct; ?>%"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ═══ Chart Scripts ═══ -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const isDark = document.documentElement.classList.contains('dark');
                const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
                const textColor = isDark ? '#a1a1aa' : '#64748b';
                const font = { family: "'Inter', sans-serif" };

                new Chart(document.getElementById('payrollChart'), {
                    type: 'bar',
                    data: {
                        labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                        datasets: [{
                            label: 'Net Payroll ($)',
                            data: <?php echo json_encode($monthly_payroll_data); ?>,
                            backgroundColor: isDark ? 'rgba(30,58,138,0.4)' : 'rgba(30,58,138,0.15)',
                            borderColor: '#1E3A8A',
                            borderWidth: 2,
                            borderRadius: 8,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: textColor, font: { ...font, size: 11 } } } },
                        scales: {
                            x: { ticks: { color: textColor, font: { ...font, size: 10 } }, grid: { color: gridColor } },
                            y: { ticks: { color: textColor, font: { ...font, size: 10 }, callback: v => '$' + (v/1000).toFixed(0) + 'k' }, grid: { color: gridColor } }
                        }
                    }
                });

                new Chart(document.getElementById('deptChart'), {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($dept_labels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($dept_counts); ?>,
                            backgroundColor: <?php echo json_encode(array_slice($dept_colors, 0, count($dept_labels))); ?>,
                            borderWidth: 0,
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '68%',
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: { color: textColor, font: { ...font, size: 11 }, padding: 12, usePointStyle: true, pointStyle: 'circle' }
                            }
                        },
                        animation: { animateRotate: true, duration: 1000 }
                    }
                });
            });
            </script>
        </main>

        <?php include "../includes/footer.php"; ?>
    </div>
</body>
</html>
