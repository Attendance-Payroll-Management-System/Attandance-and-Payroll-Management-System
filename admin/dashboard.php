<?php
require_once '../config/db.php';

$emp_count_result = $conn->query("SELECT COUNT(*) as cnt FROM employee WHERE status = 'active'");
$workforce_count = $emp_count_result->fetch_assoc()['cnt'];

$active_leaves_result = $conn->query("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'Approved' AND start_date <= CURDATE() AND end_date >= CURDATE()");
$active_leaves = $active_leaves_result->fetch_assoc()['cnt'];

$pending_leaves_result = $conn->query("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'Pending'");
$pending_leaves = $pending_leaves_result->fetch_assoc()['cnt'];

$pending_ot_result = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending'");
$pending_ot = $pending_ot_result->fetch_assoc()['cnt'];
$pending_actions = $pending_leaves + $pending_ot;

$gross_payroll_result = $conn->query("SELECT COALESCE(SUM(gross_salary), 0) as total FROM payrolls WHERE payroll_month = MONTH(CURDATE()) AND payroll_year = YEAR(CURDATE())");
$gross_total = $gross_payroll_result->fetch_assoc()['total'];

$department_result = $conn->query("SELECT d.department_name, COUNT(e.id) as emp_count FROM departments d LEFT JOIN employee e ON e.department_id = d.id GROUP BY d.id");
$departments = $department_result->fetch_all(MYSQLI_ASSOC);
$total_dept_emps = array_sum(array_column($departments, 'emp_count'));

$recent_notifications = $conn->query("
    SELECT n.*, e.name as emp_name
    FROM notifications n
    LEFT JOIN employee e ON n.employee_id = e.id
    WHERE n.employee_id IS NULL
    ORDER BY n.created_at DESC LIMIT 5
");
$notifications = $recent_notifications->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Dashboard | Enterprise HR</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex">

    <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col border-r border-slate-800 sticky top-0 h-screen">
        <div class="px-6 py-5 border-b border-slate-800 flex items-center space-x-2">
            <span class="text-xl font-bold text-white tracking-tight">Enterprise HR</span>
        </div>
        <nav class="flex-1 px-4 py-4 space-y-1 text-sm font-medium">
            <a href="dashboard.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg bg-blue-600 text-white">
                <i class="fa-solid fa-chart-pie w-4"></i> <span>Dashboard</span>
            </a>
            <a href="employee.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
                <i class="fa-solid fa-users w-4"></i> <span>Employees</span>
            </a>
            <a href="leaveApproval.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition relative">
                <i class="fa-solid fa-envelope-open-text w-4"></i> <span>Leave Requests</span>
                <?php if ($pending_leaves > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php echo $pending_leaves; ?></span>
                <?php endif; ?>
            </a>
            <a href="overtimeApproval.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition relative">
                <i class="fa-solid fa-clock w-4"></i> <span>Overtime</span>
                <?php if ($pending_ot > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php echo $pending_ot; ?></span>
                <?php endif; ?>
            </a>
            <a href="payroll.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
                <i class="fa-solid fa-coins w-4"></i> <span>Payroll</span>
            </a>
            <div class="pt-4 mt-4 border-t border-slate-800">
                <span class="px-3 text-xs font-bold uppercase tracking-wider text-slate-500 block mb-2">Management</span>
                <a href="reports.php" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
                    <i class="fa-solid fa-file-lines w-4"></i> <span>Reports</span>
                </a>
            </div>
        </nav>
        <div class="p-4 border-t border-slate-800 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 bg-slate-700 rounded-full flex items-center justify-center text-sm font-bold text-white">HR</div>
                <div>
                    <p class="text-xs font-semibold text-white leading-tight">Admin</p>
                    <p class="text-[11px] text-slate-500">HR Administrator</p>
                </div>
            </div>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 sticky top-0 z-10">
            <div>
                <h1 class="text-lg font-bold text-slate-900">Executive Dashboard</h1>
            </div>
            <div class="flex items-center space-x-4">
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="relative p-2 text-slate-500 hover:text-slate-700 bg-slate-100 rounded-full">
                        <i class="fa-solid fa-bell text-lg"></i>
                        <?php if (count($notifications) > 0): ?>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </button>
                    <div x-show="open" @click.outside="open = false" class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-slate-200 z-50" style="display: none;">
                        <div class="p-3 border-b border-slate-100">
                            <h4 class="text-sm font-bold text-slate-800">Recent Requests</h4>
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            <?php if (empty($notifications)): ?>
                                <p class="p-4 text-xs text-slate-400 text-center">No recent notifications</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $n): ?>
                                <div class="px-4 py-3 border-b border-slate-50">
                                    <p class="text-xs text-slate-700"><?php echo htmlspecialchars($n['message']); ?></p>
                                    <p class="text-[10px] text-slate-400 mt-1"><?php echo $n['emp_name'] ? htmlspecialchars($n['emp_name']) . ' - ' : ''; ?><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></p>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="p-2 border-t border-slate-100 text-center">
                            <a href="leaveApproval.php" class="text-xs text-blue-600 font-semibold">View all requests</a>
                        </div>
                    </div>
                </div>
                <a href="payroll.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg shadow-sm transition">
                    <i class="fa-solid fa-coins"></i> Payroll
                </a>
            </div>
        </header>

        <main class="p-8 space-y-6 flex-1 max-w-[1600px] w-full mx-auto">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Workforce</span>
                    <div class="text-3xl font-bold text-slate-900 mt-2"><?php echo number_format($workforce_count); ?></div>
                    <span class="text-xs text-emerald-600 font-medium mt-1 inline-block">Active employees</span>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Active Leaves</span>
                    <div class="text-3xl font-bold text-slate-900 mt-2"><?php echo $active_leaves; ?></div>
                    <span class="text-xs text-slate-500 font-medium mt-1 inline-block">Employees on leave today</span>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Pending Approvals</span>
                    <div class="text-3xl font-bold text-slate-900 mt-2"><?php echo $pending_actions; ?></div>
                    <span class="text-xs text-amber-600 font-medium mt-1 inline-block"><?php echo $pending_leaves; ?> leave / <?php echo $pending_ot; ?> OT</span>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Gross Payroll</span>
                    <div class="text-3xl font-bold text-slate-900 mt-2">$<?php echo $gross_total >= 1000000 ? number_format($gross_total / 1000000, 1) . 'M' : number_format($gross_total); ?></div>
                    <span class="text-xs text-emerald-600 font-medium mt-1 inline-block">Current month</span>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 xl:col-span-2 space-y-4">
                    <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                        <h3 class="font-bold text-slate-900 text-base">Recent Notifications</h3>
                        <a href="leaveApproval.php" class="text-xs text-blue-600 font-semibold hover:underline">View All</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead>
                                <tr class="text-slate-400 text-xs font-bold uppercase tracking-wider border-b border-slate-100">
                                    <th class="pb-3">Time</th>
                                    <th class="pb-3">From</th>
                                    <th class="pb-3">Message</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                                <?php if (empty($notifications)): ?>
                                <tr><td colspan="3" class="py-6 text-center text-slate-400">No recent activity.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($notifications as $n): ?>
                                    <tr>
                                        <td class="py-3.5 text-slate-400 text-xs"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></td>
                                        <td class="py-3.5 text-slate-900 font-semibold"><?php echo htmlspecialchars($n['emp_name'] ?? 'System'); ?></td>
                                        <td class="py-3.5 text-slate-600"><?php echo htmlspecialchars($n['message']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-4">
                        <h3 class="font-bold text-slate-900 text-base border-b border-slate-100 pb-3">Department Density</h3>
                        <div class="space-y-3.5">
                            <?php foreach ($departments as $dept): 
                                $pct = $total_dept_emps > 0 ? round(($dept['emp_count'] / $total_dept_emps) * 100) : 0;
                            ?>
                                <div class="space-y-1">
                                    <div class="flex justify-between text-xs font-semibold">
                                        <span class="text-slate-700"><?php echo htmlspecialchars($dept['department_name'] ?? 'Unassigned'); ?></span>
                                        <span class="text-slate-500"><?php echo $dept['emp_count']; ?> (<?php echo $pct; ?>%)</span>
                                    </div>
                                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                        <div class="bg-blue-600 h-full" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-blue-900 to-slate-900 text-white rounded-xl p-5 shadow-sm space-y-3">
                        <div>
                            <h4 class="text-sm font-bold tracking-tight">Quick Actions</h4>
                            <p class="text-xs text-slate-300 mt-1">Manage pending approvals and run payroll.</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="leaveApproval.php" class="flex-1 bg-white/10 hover:bg-white/15 text-white font-semibold text-xs py-2 rounded-lg border border-white/10 transition text-center">
                                <i class="fa-solid fa-check"></i> Approve Leaves
                            </a>
                            <a href="payroll.php" class="flex-1 bg-white/10 hover:bg-white/15 text-white font-semibold text-xs py-2 rounded-lg border border-white/10 transition text-center">
                                <i class="fa-solid fa-calculator"></i> Run Payroll
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <footer class="bg-white border-t border-slate-200 px-8 py-3 text-xs text-slate-400 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> ENTERPRISE HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-600">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>
