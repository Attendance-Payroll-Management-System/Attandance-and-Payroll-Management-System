<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
require_once '../config/db.php';

$emp_count_result = $conn->query("SELECT COUNT(*) as cnt FROM employee WHERE status = 'active'");
$workforce_count = $emp_count_result->fetch_assoc()['cnt'];

$active_leaves_result = $conn->query("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'Approved' AND start_date <= CURDATE() AND end_date >= CURDATE()");
$active_leaves = $active_leaves_result->fetch_assoc()['cnt'];

$pending_leaves_result = $conn->query("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'Pending'");
$pending_leaves = $pending_leaves_result->fetch_assoc()['cnt'];

$has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;
if ($has_source) {
    $pending_ot_result = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending' AND (source IS NULL OR source = 'employee_request')");
    $pending_ot = $pending_ot_result->fetch_assoc()['cnt'];
    $pending_ot_assignments = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending' AND source = 'admin_assigned'")->fetch_assoc()['cnt'];
} else {
    $pending_ot_result = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending'");
    $pending_ot = $pending_ot_result->fetch_assoc()['cnt'];
    $pending_ot_assignments = 0;
}
$pending_actions = $pending_leaves + $pending_ot;

$gross_payroll_result = $conn->query("SELECT COALESCE(SUM(gross_salary), 0) as total FROM payrolls WHERE payroll_month = MONTH(CURDATE()) AND payroll_year = YEAR(CURDATE())");
$gross_total = $gross_payroll_result->fetch_assoc()['total'];

$department_result = $conn->query("SELECT d.department_name, COUNT(e.id) as emp_count FROM departments d LEFT JOIN employee e ON e.department_id = d.id GROUP BY d.id");
$departments = $department_result->fetch_all(MYSQLI_ASSOC);
$total_dept_emps = array_sum(array_column($departments, 'emp_count'));

$today_checkins = $conn->query("SELECT COUNT(*) as cnt FROM attendance WHERE attendance_date = CURDATE() AND check_in IS NOT NULL")->fetch_assoc()['cnt'];
$today_absent = $workforce_count - $today_checkins;

$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$monthly_att = $conn->query("SELECT COUNT(DISTINCT a.employee_id) as total_employees, COUNT(DISTINCT CASE WHEN a.check_in IS NOT NULL THEN a.employee_id END) as present_employees, COUNT(*) as total_records, SUM(CASE WHEN a.check_in IS NOT NULL THEN 1 ELSE 0 END) as present_count FROM attendance a WHERE a.attendance_date BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();
$attendance_rate = $monthly_att['total_records'] > 0 ? round(($monthly_att['present_count'] / $monthly_att['total_records']) * 100, 1) : 0;

$monthly_leaves = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected, SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending FROM leave_requests WHERE created_at BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();

if ($has_source) {
    $monthly_ot = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected, SUM(CASE WHEN status = 'Pending' AND (source IS NULL OR source = 'employee_request') THEN 1 ELSE 0 END) as pending FROM overtime_requests WHERE created_at BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();
} else {
    $monthly_ot = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected, SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending FROM overtime_requests WHERE created_at BETWEEN '$month_start' AND '$month_end'")->fetch_assoc();
}

$recent_notifications = $conn->query("SELECT n.*, e.name as emp_name FROM notifications n LEFT JOIN employee e ON n.employee_id = e.id WHERE n.employee_id IS NULL ORDER BY n.created_at DESC LIMIT 5");
$notifications = $recent_notifications->fetch_all(MYSQLI_ASSOC);

$monthly_payroll_data = [];
for ($m = 1; $m <= 12; $m++) {
    $res = $conn->query("SELECT COALESCE(SUM(net_salary), 0) as total FROM payrolls WHERE payroll_month = $m AND payroll_year = " . date('Y'));
    $monthly_payroll_data[] = (float)$res->fetch_assoc()['total'];
}

$dept_labels = [];
$dept_counts = [];
$dept_colors = ['#8B5CF6', '#D946EF', '#F59E0B', '#10B981', '#3B82F6', '#EF4444', '#14B8A6', '#F97316'];
foreach ($departments as $i => $dept) {
    $dept_labels[] = $dept['department_name'] ?? 'Unassigned';
    $dept_counts[] = (int)$dept['emp_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Executive Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false, notifOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <header class="glass-strong px-6 lg:px-8 py-4 flex items-center justify-between shrink-0 sticky top-0 z-10">
            <div>
                <h1 class="text-lg font-bold text-white">Executive Dashboard</h1>
                <p class="text-xs text-zinc-500"><?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="flex items-center space-x-4">
                <button onclick="toggleTheme()" class="theme-toggle-btn">
                    <i class="fa-solid fa-sun icon-sun text-base"></i>
                    <i class="fa-solid fa-moon icon-moon text-base"></i>
                </button>
                <div class="relative">
                    <button @click="notifOpen = !notifOpen" class="relative p-2 text-zinc-400 hover:text-white glass rounded-full transition">
                        <i class="fa-solid fa-bell text-lg"></i>
                        <?php if (count($notifications) > 0): ?>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-rose-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </button>
                    <div x-show="notifOpen" @click.outside="notifOpen = false" class="absolute right-0 mt-2 w-80 glass-strong rounded-xl shadow-xl border border-white/10 z-50" style="display: none;">
                        <div class="p-3 border-b border-white/[0.06]">
                            <h4 class="text-sm font-bold text-white">Recent Requests</h4>
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            <?php if (empty($notifications)): ?>
                                <p class="p-4 text-xs text-zinc-500 text-center">No recent notifications</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $n): ?>
                                <div class="px-4 py-3 border-b border-white/[0.04] hover:bg-white/[0.02] transition">
                                    <p class="text-xs text-zinc-300"><?php echo htmlspecialchars($n['message']); ?></p>
                                    <p class="text-[10px] text-zinc-500 mt-1"><?php echo $n['emp_name'] ? htmlspecialchars($n['emp_name']) . ' - ' : ''; ?><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></p>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="p-2 border-t border-white/[0.06] text-center">
                            <a href="leaveApproval.php" class="text-xs text-violet-400 font-semibold hover:text-violet-300 transition-colors">View all requests</a>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 glass rounded-full px-4 py-1.5">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-xs font-bold shadow-lg">AD</div>
                    <div class="text-sm font-semibold text-white">Admin</div>
                </div>
            </div>
        </header>

        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="group glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-1">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-users mr-1 text-violet-400"></i>Workforce</span>
                            <div class="text-3xl font-bold text-gradient mt-1"><?php echo number_format($workforce_count); ?></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-violet-500/20 to-fuchsia-500/20 text-violet-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-users"></i></div>
                    </div>
                    <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: 100%"></div></div>
                    <span class="text-xs text-emerald-400 font-medium mt-2 inline-block"><i class="fa-solid fa-circle-check mr-1"></i>Active employees</span>
                </div>
                <div class="group glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-2">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-calendar-check mr-1 text-blue-400"></i>Today's Attendance</span>
                            <div class="text-3xl font-bold text-gradient-violet mt-1"><?php echo $today_checkins; ?> <span class="text-base font-medium text-zinc-500">/ <?php echo $workforce_count; ?></span></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-cyan-500/20 text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-calendar-check"></i></div>
                    </div>
                    <?php $att_pct = $workforce_count > 0 ? round(($today_checkins / $workforce_count) * 100) : 0; ?>
                    <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: <?php echo $att_pct; ?>%"></div></div>
                    <span class="text-xs <?php echo $today_absent > 0 ? 'text-amber-400' : 'text-emerald-400'; ?> font-medium mt-2 inline-block"><i class="fa-solid fa-circle mr-1"></i><?php echo $today_absent; ?> absent today</span>
                </div>
                <div class="group glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-3">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-chart-line mr-1 text-emerald-400"></i>Attendance Rate</span>
                            <div class="text-3xl font-bold text-gradient-emerald mt-1"><?php echo $attendance_rate; ?>%</div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-chart-line"></i></div>
                    </div>
                    <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: <?php echo $attendance_rate; ?>%"></div></div>
                    <span class="text-xs text-zinc-400 font-medium mt-2 inline-block"><?php echo date('F Y'); ?></span>
                </div>
                <div class="group glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-file-invoice-dollar mr-1 text-cyan-400"></i>Gross Payroll</span>
                            <div class="text-3xl font-bold text-gradient-amber mt-1"><?php echo $gross_total >= 1000000 ? '$' . number_format($gross_total / 1000000, 1) . 'M' : '$' . number_format($gross_total); ?></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-500/20 text-cyan-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    </div>
                    <span class="text-xs text-emerald-400 font-medium mt-2 inline-block"><i class="fa-solid fa-calendar mr-1"></i>Current month</span>
                </div>
                <div class="group glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-plane-departure mr-1 text-amber-400"></i>Active Leaves</span>
                            <div class="text-3xl font-bold text-white mt-1"><?php echo $active_leaves; ?></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/20 text-amber-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-plane-departure"></i></div>
                    </div>
                    <span class="text-xs text-zinc-400 font-medium mt-2 inline-block">On leave today</span>
                </div>
                <div class="group glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-6">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-envelope mr-1 text-rose-400"></i>Leave Flow</span>
                            <div class="text-3xl font-bold text-white mt-1"><?php echo $monthly_leaves['total']; ?> <span class="text-base font-medium text-zinc-500">requests</span></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-500/20 to-pink-500/20 text-rose-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-envelope"></i></div>
                    </div>
                    <span class="text-xs mt-2 inline-flex items-center gap-2"><span class="badge badge-emerald"><?php echo $monthly_leaves['approved']; ?> approved</span><span class="badge badge-amber"><?php echo $monthly_leaves['pending']; ?> pending</span></span>
                </div>
                <div class="group glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-7">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-clock mr-1 text-purple-400"></i>Overtime Flow</span>
                            <div class="text-3xl font-bold text-white mt-1"><?php echo $monthly_ot['total']; ?> <span class="text-base font-medium text-zinc-500">requests</span></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-clock"></i></div>
                    </div>
                    <span class="text-xs mt-2 inline-flex items-center gap-2"><span class="badge badge-emerald"><?php echo $monthly_ot['approved']; ?> approved</span><span class="badge badge-amber"><?php echo $monthly_ot['pending']; ?> pending</span></span>
                </div>
                <div class="group glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-8">
                    <div class="flex items-start justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-500"><i class="fa-solid fa-bell mr-1 text-rose-400"></i>Pending Approvals</span>
                            <div class="text-3xl font-bold text-white mt-1"><?php echo $pending_actions; ?></div>
                        </div>
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-500/20 to-red-500/20 text-rose-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-bell"></i></div>
                    </div>
                    <span class="text-xs mt-2 inline-flex items-center gap-2"><span class="badge badge-rose"><?php echo $pending_leaves; ?> leave</span><span class="badge badge-amber"><?php echo $pending_ot; ?> OT</span></span>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-1">
                    <h3 class="font-bold text-white text-base mb-4 border-b border-white/[0.06] pb-3 flex items-center justify-between">
                        <span><i class="fa-solid fa-chart-line text-violet-400 mr-2"></i>Monthly Payroll Trend (<?php echo date('Y'); ?>)</span>
                        <span class="badge badge-slate text-[9px]">Annual</span>
                    </h3>
                    <div class="relative" style="height: 240px;">
                        <canvas id="payrollChart"></canvas>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-2">
                    <h3 class="font-bold text-white text-base mb-4 border-b border-white/[0.06] pb-3"><i class="fa-solid fa-chart-pie text-violet-400 mr-2"></i>Department Distribution</h3>
                    <div class="relative" style="height: 240px;">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="glass-strong rounded-2xl p-6 xl:col-span-2 space-y-4 card-hover animate-fade-in-up stagger-1">
                    <div class="flex justify-between items-center border-b border-white/[0.06] pb-3">
                        <h3 class="font-bold text-white text-base"><i class="fa-solid fa-bell text-violet-400 mr-2"></i>Recent Notifications</h3>
                        <a href="leaveApproval.php" class="text-xs font-semibold text-violet-400 hover:text-violet-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>View All</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                    <th class="pb-3 pr-4">Time</th>
                                    <th class="pb-3 pr-4">From</th>
                                    <th class="pb-3">Message</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04] font-medium text-zinc-300">
                                <?php if (empty($notifications)): ?>
                                <tr><td colspan="3" class="py-8 text-center text-zinc-500">
                                    <div class="flex flex-col items-center gap-2">
                                        <i class="fa-regular fa-bell-slash text-2xl text-zinc-600"></i>
                                        <p class="text-sm">No recent activity.</p>
                                    </div>
                                </td></tr>
                                <?php else: ?>
                                    <?php foreach ($notifications as $n): ?>
                                    <tr class="hover:bg-white/[0.02] transition-colors duration-150">
                                        <td class="py-3.5 pr-4 text-zinc-500 text-xs font-mono"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></td>
                                        <td class="py-3.5 pr-4 text-white font-semibold"><?php echo htmlspecialchars($n['emp_name'] ?? 'System'); ?></td>
                                        <td class="py-3.5 text-zinc-400"><?php echo htmlspecialchars($n['message']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="glass-strong rounded-2xl p-6 space-y-4 card-hover animate-fade-in-up stagger-2">
                        <h3 class="font-bold text-white text-base border-b border-white/[0.06] pb-3"><i class="fa-solid fa-building text-violet-400 mr-2"></i>Department Density</h3>
                        <div class="space-y-4">
                            <?php foreach ($departments as $dept): 
                                $pct = $total_dept_emps > 0 ? round(($dept['emp_count'] / $total_dept_emps) * 100) : 0;
                            ?>
                                <div class="space-y-1.5">
                                    <div class="flex justify-between text-xs font-semibold">
                                        <span class="text-zinc-300"><?php echo htmlspecialchars($dept['department_name'] ?? 'Unassigned'); ?></span>
                                        <span class="text-zinc-500"><?php echo $dept['emp_count']; ?> <span class="text-zinc-600 font-normal">/ <?php echo $pct; ?>%</span></span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-bar-fill" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-violet-600 via-fuchsia-600 to-amber-600 text-white rounded-2xl p-6 shadow-xl shadow-violet-600/10 space-y-4 card-hover animate-fade-in-up stagger-3 card-inner-glow">
                        <div>
                            <h4 class="text-base font-bold tracking-tight"><i class="fa-solid fa-bolt mr-2"></i>Quick Actions</h4>
                            <p class="text-xs text-white/60 mt-1">Manage pending approvals and run payroll.</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3 relative z-10">
                            <a href="leaveApproval.php" class="bg-white/15 hover:bg-white/25 text-white font-semibold text-xs py-3 rounded-xl border border-white/10 transition-all duration-200 text-center backdrop-blur-sm hover:scale-105 active:scale-95">
                                <i class="fa-solid fa-check-circle mr-1"></i> Approve Leaves
                            </a>
                            <a href="overtimeApproval.php" class="bg-white/15 hover:bg-white/25 text-white font-semibold text-xs py-3 rounded-xl border border-white/10 transition-all duration-200 text-center backdrop-blur-sm hover:scale-105 active:scale-95">
                                <i class="fa-solid fa-clock mr-1"></i> Approve OT
                            </a>
                            <a href="payroll.php" class="bg-white/15 hover:bg-white/25 text-white font-semibold text-xs py-3 rounded-xl border border-white/10 transition-all duration-200 text-center backdrop-blur-sm hover:scale-105 active:scale-95">
                                <i class="fa-solid fa-calculator mr-1"></i> Run Payroll
                            </a>
                            <a href="employee.php" class="bg-white/15 hover:bg-white/25 text-white font-semibold text-xs py-3 rounded-xl border border-white/10 transition-all duration-200 text-center backdrop-blur-sm hover:scale-105 active:scale-95">
                                <i class="fa-solid fa-user-plus mr-1"></i> Add Employee
                            </a>
                        </div>
                    </div>
                </div>
            </div>

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
                            backgroundColor: isDark ? 'rgba(139,92,246,0.4)' : 'rgba(139,92,246,0.15)',
                            borderColor: '#8B5CF6',
                            borderWidth: 2,
                            borderRadius: 8,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { labels: { color: textColor, font: { ...font, size: 11 } } }
                        },
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

        <footer class="glass-strong border-t border-white/[0.06] px-6 lg:px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> AURA HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
<script>
function toggleTheme() {
    var html = document.documentElement;
    var isDark = html.classList.contains('dark');
    if (isDark) {
        html.classList.remove('dark');
        localStorage.setItem('aura-theme', 'light');
    } else {
        html.classList.add('dark');
        localStorage.setItem('aura-theme', 'dark');
    }
}
</script>
</body>
</html>