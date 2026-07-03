<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));
$days_in_month = date('t', strtotime($month_start));

$day_names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Fetch holidays for the month
$holiday_map = [];
$conn->query("CREATE TABLE IF NOT EXISTS holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    holiday_name VARCHAR(100) NOT NULL,
    holiday_date DATE NOT NULL,
    year YEAR NOT NULL,
    type VARCHAR(30) DEFAULT 'Public',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (holiday_date)
)");
$h_sql = "SELECT holiday_date, holiday_name, type FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date ASC";
$h_stmt = $conn->prepare($h_sql);
$h_stmt->bind_param('ss', $month_start, $month_end);
$h_stmt->execute();
foreach ($h_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $h) {
    $holiday_map[$h['holiday_date']] = $h['holiday_name'];
}
$h_stmt->close();

// Build weeks: group days of month into week chunks (Mon-Sun)
$weeks = [];
$week_start = new DateTime($month_start);
// Find the Monday of the week containing the 1st
$week_start->modify('monday this week');
$month_end_dt = new DateTime($month_end);

while ($week_start <= $month_end_dt) {
    $week_days = [];
    for ($d = 0; $d < 7; $d++) {
        $date = clone $week_start;
        $date->modify("+$d days");
        $ymd = $date->format('Y-m-d');
        if ($ymd >= $month_start && $ymd <= $month_end) {
            $week_days[] = $ymd;
        }
    }
    if (!empty($week_days)) {
        $weeks[] = $week_days;
    }
    $week_start->modify('+7 days');
}

// Fetch attendance records for the month
$att_sql = "SELECT a.*, e.name, e.employee_code, d.department_name
FROM attendance a
JOIN employee e ON a.employee_id = e.id
LEFT JOIN departments d ON e.department_id = d.id
WHERE a.attendance_date BETWEEN ? AND ? AND e.status = 'active'
ORDER BY e.name ASC, a.attendance_date ASC";

$stmt = $conn->prepare($att_sql);
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$att_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build employee map [employee_id => {name, code, dept}]
// and attendance map [employee_id][date] => status
$employees = [];
$att_map = [];
foreach ($att_rows as $row) {
    $eid = $row['employee_id'];
    if (!isset($employees[$eid])) {
        $employees[$eid] = [
            'name' => $row['name'],
            'code' => $row['employee_code'],
            'dept' => $row['department_name'] ?? '-',
        ];
    }
    $att_map[$eid][$row['attendance_date']] = $row['status'];
}

// Summary calculations
$total_employees = count($employees);
$status_counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0];
$total_records = 0;
foreach ($att_map as $eid => $days) {
    foreach ($days as $date => $status) {
        if (isset($status_counts[$status])) {
            $status_counts[$status]++;
        }
        $total_records++;
    }
}
$attendance_rate = $total_records > 0 ? round(($status_counts['present'] / $total_records) * 100, 1) : 0;

function status_badge($status) {
    return match($status) {
        'present' => 'bg-emerald-500',
        'absent' => 'bg-red-500',
        'late' => 'bg-amber-400',
        'leave' => 'bg-blue-500',
        default => 'bg-gray-200',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Monthly Attendance</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Monthly Attendance"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
                <div class="animate-fade-in-up">
                    <h1 class="text-2xl font-bold text-body tracking-tight">Monthly Attendance</h1>
                    <p class="text-sm text-body-secondary mt-1">Weekly attendance grid for <?php echo $month_name . ' ' . $selected_year; ?></p>
                </div>
                <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
                    <select name="month" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="year" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                        <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass"></i> View
                    </button>
                </form>
            </header>

            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Active Employees</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_employees; ?></p>
                    <span class="text-xs text-zinc-500"><?php echo $days_in_month; ?> days</span>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Attendance Rate</span>
                    <p class="text-2xl font-bold text-emerald-400"><?php echo $attendance_rate; ?>%</p>
                    <span class="text-xs text-zinc-500">Present / total</span>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-400"><i class="fa-solid fa-circle text-[8px] mr-1"></i>Present</span>
                    <p class="text-2xl font-bold text-white"><?php echo $status_counts['present']; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-amber-500"><i class="fa-solid fa-circle text-[8px] mr-1"></i>Late</span>
                    <p class="text-2xl font-bold text-white"><?php echo $status_counts['late']; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex gap-3 text-xs font-bold uppercase tracking-wider">
                        <span class="text-red-400"><i class="fa-solid fa-circle text-[8px] mr-1"></i>Absent <?php echo $status_counts['absent']; ?></span>
                        <span class="text-blue-400"><i class="fa-solid fa-circle text-[8px] mr-1"></i>Leave <?php echo $status_counts['leave']; ?></span>
                    </div>
                    <div class="w-full bg-white/[0.06] h-3 rounded-full overflow-hidden mt-3">
                        <div class="bg-emerald-500 h-full rounded-full" style="width: <?php echo $attendance_rate; ?>%"></div>
                    </div>
                </div>
            </section>

            <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-calendar-days text-violet-400 mr-2"></i>Weekly Attendance Matrix</h2>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo $total_employees; ?> employees</span>
                </div>
                <div class="overflow-x-auto" style="max-height: 600px; overflow-y: auto;">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-white/[0.04] sticky top-0 z-10">
                            <tr class="border-b border-white/[0.06]">
                                <th class="px-4 py-3 text-left font-semibold text-zinc-400 min-w-[140px] bg-white/[0.04] sticky left-0 z-20">Employee</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-400 min-w-[80px] bg-white/[0.04] sticky left-[140px] z-20">Code</th>
                                <?php foreach ($weeks as $wi => $week): ?>
                                <th colspan="<?php echo count($week); ?>" class="px-1 py-3 text-center font-semibold text-indigo-400 bg-indigo-500/10 border-x border-white/[0.06] min-w-[<?php echo count($week) * 32; ?>px]">
                                    Week <?php echo $wi + 1; ?>
                                </th>
                                <?php endforeach; ?>
                                <th class="px-3 py-3 text-center font-semibold text-zinc-400 min-w-[60px]">Rate</th>
                            </tr>
                            <tr class="border-b border-white/[0.06]">
                                <th class="px-4 py-2 bg-white/[0.04] sticky left-0 z-20"></th>
                                <th class="px-4 py-2 bg-white/[0.04] sticky left-[140px] z-20"></th>
                                <?php foreach ($weeks as $week): ?>
                                    <?php foreach ($week as $day_ymd): ?>
                                    <th class="px-1 py-2 text-center font-medium text-zinc-500 border-x border-white/[0.06] w-8">
                                        <?php echo date('D', strtotime($day_ymd)); ?>
                                    </th>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <th class="px-3 py-2"></th>
                            </tr>
                            <tr class="border-b border-white/[0.06] text-zinc-500">
                                <th class="px-4 py-1 bg-white/[0.04] sticky left-0 z-20"></th>
                                <th class="px-4 py-1 bg-white/[0.04] sticky left-[140px] z-20"></th>
                                <?php foreach ($weeks as $week): ?>
                                    <?php foreach ($week as $day_ymd): ?>
                                    <th class="px-1 py-1 text-center font-mono text-[10px] border-x border-white/[0.06] w-8 <?php echo isset($holiday_map[$day_ymd]) ? 'bg-pink-500/10 text-pink-400 font-bold' : ''; ?>" title="<?php echo isset($holiday_map[$day_ymd]) ? htmlspecialchars($holiday_map[$day_ymd]) : ''; ?>">
                                        <?php echo date('j', strtotime($day_ymd)); ?>
                                        <?php if (isset($holiday_map[$day_ymd])): ?>
                                        <i class="fa-solid fa-star text-[6px] text-pink-500 ml-0.5 align-super"></i>
                                        <?php endif; ?>
                                    </th>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <th class="px-3 py-1"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="<?php echo 2 + $days_in_month + 1; ?>" class="px-6 py-12 text-center text-zinc-500">
                                    <p class="text-lg mb-2">No attendance data for <?php echo $month_name . ' ' . $selected_year; ?></p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($employees as $eid => $emp): ?>
                                <?php
                                    $worked = 0;
                                    foreach ($weeks as $week) {
                                        foreach ($week as $day_ymd) {
                                            $s = $att_map[$eid][$day_ymd] ?? '';
                                            if ($s === 'present' || $s === 'late') $worked++;
                                        }
                                    }
                                    $emp_rate = $days_in_month > 0 ? round(($worked / $days_in_month) * 100, 1) : 0;
                                    $rate_color = $emp_rate >= 90 ? 'text-emerald-400' : ($emp_rate >= 75 ? 'text-amber-400' : 'text-red-400');
                                ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-4 py-2.5 font-medium text-body sticky left-0 bg-body z-10"><?php echo htmlspecialchars($emp['name']); ?></td>
                                    <td class="px-4 py-2.5 text-body-secondary sticky left-[140px] bg-body z-10"><?php echo htmlspecialchars($emp['code']); ?></td>
                                    <?php foreach ($weeks as $week): ?>
                                        <?php foreach ($week as $day_ymd): ?>
                                        <?php
                                        $status = $att_map[$eid][$day_ymd] ?? '';
                                        $is_holiday = isset($holiday_map[$day_ymd]);
                                        ?>
                                        <td class="px-1 py-2.5 text-center border-x border-white/[0.06] <?php echo $is_holiday && !$status ? 'bg-pink-50/30' : ''; ?>">
                                            <?php if ($status): ?>
                                            <span class="inline-block w-4 h-4 rounded-full <?php echo status_badge($status); ?>" title="<?php echo ucfirst($status) . ' ' . date('M j', strtotime($day_ymd)); ?>"></span>
                                            <?php elseif ($is_holiday): ?>
                                            <span class="inline-block w-4 h-4 rounded-full bg-pink-300 border border-pink-400 flex items-center justify-center text-white text-[8px]" title="<?php echo htmlspecialchars($holiday_map[$day_ymd]); ?>">
                                                <i class="fa-solid fa-star"></i>
                                            </span>
                                            <?php else: ?>
                                            <span class="inline-block w-4 h-4 rounded-full bg-white/10 border border-white/10" title="<?php echo date('M j', strtotime($day_ymd)); ?>"></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <td class="px-3 py-2.5 text-center font-bold <?php echo $rate_color; ?>"><?php echo $emp_rate; ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mt-4 flex items-center gap-6 text-xs text-zinc-400">
                <span><span class="inline-block w-3 h-3 rounded-full bg-emerald-500 align-middle mr-1"></span> Present</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-amber-400 align-middle mr-1"></span> Late</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-red-500 align-middle mr-1"></span> Absent</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-blue-500 align-middle mr-1"></span> Leave</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-white/10 border border-white/10 align-middle mr-1"></span> No Record</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-pink-300 border border-pink-400 align-middle mr-1 text-pink-600 text-[10px]"><i class="fa-solid fa-star"></i></span> Holiday</span>
            </section>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> ENTERPRISE HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>
