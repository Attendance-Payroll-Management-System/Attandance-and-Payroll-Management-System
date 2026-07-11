<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)mmt_date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)mmt_date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));
$working_days = get_working_days_in_month($selected_year, $selected_month);

// Department filter
$dept_filter = isset($_GET['department']) ? (int)$_GET['department'] : 0;

// Get all employees with attendance summary
$emp_query = "SELECT e.id, e.name, e.employee_code, e.basic_salary, d.department_name,
    (SELECT COUNT(*) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as total_records,
    (SELECT SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as present_days,
    (SELECT SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as late_days,
    (SELECT SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as half_days,
    (SELECT SUM(CASE WHEN status IN ('paid_leave','leave') THEN 1 ELSE 0 END) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as paid_leave_days,
    (SELECT SUM(CASE WHEN status = 'unpaid_leave' THEN 1 ELSE 0 END) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as unpaid_leave_days,
    (SELECT SUM(CASE WHEN status IN ('awol','absent','full_absent','half_absent') THEN 1 ELSE 0 END) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as absent_days,
    (SELECT SUM(CASE WHEN status = 'weekend' THEN 1 ELSE 0 END) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as weekend_days,
    (SELECT SUM(CASE WHEN status = 'public_holiday' THEN 1 ELSE 0 END) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as holiday_days,
    (SELECT COALESCE(SUM(total_working_hours), 0) FROM attendance WHERE employee_id = e.id AND attendance_date BETWEEN ? AND ?) as total_hours,
    (SELECT COALESCE(SUM(total_hours), 0) FROM overtime_requests WHERE employee_id = e.id AND ot_date BETWEEN ? AND ? AND status = 'Approved') as ot_hours
    FROM employee e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.status = 'active'";

$params = [];
$types = '';
for ($i = 0; $i < 12; $i++) { $params[] = $month_start; $types .= 's'; }
for ($i = 0; $i < 12; $i++) { $params[] = $month_end; $types .= 's'; }

if ($dept_filter > 0) {
    $emp_query .= " AND e.department_id = ?";
    $params[] = $dept_filter;
    $types .= 'i';
}

$emp_query .= " ORDER BY e.name ASC";

$stmt = $conn->prepare($emp_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get departments for filter
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

// Calculate totals
$totals = ['present' => 0, 'late' => 0, 'half' => 0, 'paid_leave' => 0, 'unpaid_leave' => 0, 'absent' => 0, 'ot_hours' => 0, 'total_hours' => 0];
foreach ($employees as $emp) {
    $totals['present'] += (int)($emp['present_days'] ?? 0);
    $totals['late'] += (int)($emp['late_days'] ?? 0);
    $totals['half'] += (int)($emp['half_days'] ?? 0);
    $totals['paid_leave'] += (int)($emp['paid_leave_days'] ?? 0);
    $totals['unpaid_leave'] += (int)($emp['unpaid_leave_days'] ?? 0);
    $totals['absent'] += (int)($emp['absent_days'] ?? 0);
    $totals['ot_hours'] += (float)($emp['ot_hours'] ?? 0);
    $totals['total_hours'] += (float)($emp['total_hours'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Monthly Attendance Summary</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
        $page_title = "Attendance Summary";
        $page_subtitle = "Monthly attendance overview for all employees";
        ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-blue-500/15 flex items-center justify-center">
                    <i class="fa-regular fa-calendar text-blue-500 text-sm"></i>
                </div>
                <select name="month" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 min-w-[130px]">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-indigo-500/15 flex items-center justify-center">
                    <i class="fa-solid fa-clock-rotate-left text-indigo-500 text-sm"></i>
                </div>
                <select name="year" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 min-w-[100px]">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-emerald-500/15 flex items-center justify-center">
                    <i class="fa-solid fa-building text-emerald-500 text-sm"></i>
                </div>
                <select name="department" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-emerald-500/30 min-w-[150px]">
                    <option value="0">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo $d['id'] == $dept_filter ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-2.5 shadow-lg shadow-blue-500/25 transition-all duration-200 flex items-center gap-2 hover:scale-105">
                <i class="fa-solid fa-magnifying-glass"></i> View
            </button>
        </form>
        <?php $page_actions = ob_get_clean();
        include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <!-- Summary Stats -->
            <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center"><i class="fa-solid fa-calendar-check"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Present</span><div class="text-xl font-extrabold text-emerald-400"><?php echo $totals['present']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-2">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-yellow-500/20 text-amber-400 flex items-center justify-center"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Late</span><div class="text-xl font-extrabold text-amber-400"><?php echo $totals['late']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-500/20 to-cyan-500/20 text-teal-400 flex items-center justify-center"><i class="fa-solid fa-clock"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Half Day</span><div class="text-xl font-extrabold text-teal-400"><?php echo $totals['half']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500/20 to-blue-500/20 text-sky-400 flex items-center justify-center"><i class="fa-solid fa-plane-departure"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Leave</span><div class="text-xl font-extrabold text-sky-400"><?php echo $totals['paid_leave'] + $totals['unpaid_leave']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500/20 to-rose-500/20 text-red-400 flex items-center justify-center"><i class="fa-solid fa-calendar-xmark"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Absent</span><div class="text-xl font-extrabold text-red-400"><?php echo $totals['absent']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-400 flex items-center justify-center"><i class="fa-solid fa-stopwatch"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">OT Hours</span><div class="text-xl font-extrabold text-purple-400"><?php echo number_format($totals['ot_hours'], 1); ?>h</div></div>
                    </div>
                </div>
            </section>

            <!-- Attendance Summary Table -->
            <section class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-7">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-table text-blue-500"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white">Monthly Attendance Detail</h2>
                            <p class="text-xs text-zinc-500 mt-0.5"><?php echo $month_name . ' ' . $selected_year; ?> · <?php echo count($employees); ?> employees · <?php echo $working_days; ?> working days</p>
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20">
                        <i class="fa-solid fa-info-circle text-indigo-400 text-xs"></i>
                        <span class="text-xs font-semibold text-indigo-400">Working: <?php echo $working_days; ?> days</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-5 py-4">Employee</th>
                                <th class="px-4 py-4 text-center" title="Working Days"><span class="text-blue-400">Work</span></th>
                                <th class="px-4 py-4 text-center" title="Present Days"><span class="text-emerald-400">Present</span></th>
                                <th class="px-4 py-4 text-center" title="Late Days"><span class="text-amber-400">Late</span></th>
                                <th class="px-4 py-4 text-center" title="Half Days"><span class="text-teal-400">Half</span></th>
                                <th class="px-4 py-4 text-center" title="Paid Leave Days"><span class="text-sky-400">P.Leave</span></th>
                                <th class="px-4 py-4 text-center" title="Unpaid Leave Days"><span class="text-orange-400">UP.Leave</span></th>
                                <th class="px-4 py-4 text-center" title="Absent Days"><span class="text-red-400">Absent</span></th>
                                <th class="px-4 py-4 text-center" title="Overtime Hours"><span class="text-purple-400">OT Hrs</span></th>
                                <th class="px-4 py-4 text-center" title="Total Hours Worked"><span class="text-cyan-400">Total Hrs</span></th>
                                <th class="px-5 py-4 text-center">Att. Rate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($employees)): ?>
                            <tr><td colspan="11" class="px-6 py-16 text-center text-zinc-500">
                                <i class="fa-solid fa-users text-2xl text-zinc-600 block mb-2"></i>No employees found.
                            </td></tr>
                            <?php else: foreach ($employees as $idx => $emp):
                                $eff_present = (int)($emp['present_days'] ?? 0);
                                $att_rate = $working_days > 0 ? round(($eff_present / $working_days) * 100, 1) : 0;
                                $rate_color = $att_rate >= 80 ? 'text-emerald-400' : ($att_rate >= 50 ? 'text-amber-400' : 'text-red-400');
                            ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-5 py-3">
                                    <div class="font-semibold text-white text-xs"><?php echo htmlspecialchars($emp['name']); ?></div>
                                    <div class="text-[10px] text-zinc-500"><?php echo htmlspecialchars($emp['employee_code'] . ' · ' . ($emp['department_name'] ?? 'N/A')); ?></div>
                                </td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-zinc-300"><?php echo $working_days; ?></span></td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-emerald-400"><?php echo $eff_present; ?></span></td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-amber-400"><?php echo (int)($emp['late_days'] ?? 0); ?></span></td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-teal-400"><?php echo (int)($emp['half_days'] ?? 0); ?></span></td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-sky-400"><?php echo (int)($emp['paid_leave_days'] ?? 0); ?></span></td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-orange-400"><?php echo (int)($emp['unpaid_leave_days'] ?? 0); ?></span></td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-red-400"><?php echo (int)($emp['absent_days'] ?? 0); ?></span></td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-purple-400"><?php echo number_format((float)($emp['ot_hours'] ?? 0), 1); ?>h</span></td>
                                <td class="px-4 py-3 text-center"><span class="text-xs font-bold text-cyan-400"><?php echo number_format((float)($emp['total_hours'] ?? 0), 1); ?>h</span></td>
                                <td class="px-5 py-3 text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold <?php echo $att_rate >= 80 ? 'bg-emerald-500/20 text-emerald-400' : ($att_rate >= 50 ? 'bg-amber-500/20 text-amber-400' : 'bg-red-500/20 text-red-400'); ?>">
                                        <?php echo $att_rate; ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
        <?php include "../includes/footer.php"; ?>
    </div>
</body>
</html>
