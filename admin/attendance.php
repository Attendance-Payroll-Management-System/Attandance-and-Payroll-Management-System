<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));
$days_in_month = date('t', strtotime($month_start));

$day_names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Fetch departments for filter
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name ASC")->fetch_all(MYSQLI_ASSOC);

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

// Build weeks
$weeks = [];
$week_start = new DateTime($month_start);
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

// Fetch attendance records
$att_sql = "SELECT a.*, e.name, e.employee_code, e.profile_photo, p.position_name, d.department_name
FROM attendance a
JOIN employee e ON a.employee_id = e.id
LEFT JOIN positions p ON e.position_id = p.id
LEFT JOIN departments d ON e.department_id = d.id
WHERE a.attendance_date BETWEEN ? AND ? AND e.status = 'active'
ORDER BY e.name ASC, a.attendance_date ASC";

$stmt = $conn->prepare($att_sql);
$stmt->bind_param('ss', $month_start, $month_end);
$stmt->execute();
$att_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build employee data and attendance map
$employees = [];
$att_map = [];
foreach ($att_rows as $row) {
    $eid = $row['employee_id'];
    if (!isset($employees[$eid])) {
        $employees[$eid] = [
            'id' => $eid,
            'name' => $row['name'],
            'code' => $row['employee_code'],
            'profile_photo' => $row['profile_photo'] ?? '',
            'position' => $row['position_name'] ?? '-',
            'department' => $row['department_name'] ?? '-',
        ];
    }
    $att_map[$eid][$row['attendance_date']] = $row['status'];
}

// Summary calculations
$total_employees = count($employees);
$status_counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_absent' => 0, 'full_absent' => 0, 'awol' => 0, 'public_holiday' => 0, 'weekend' => 0];
$total_records = 0;
foreach ($att_map as $eid => $days) {
    foreach ($days as $date => $status) {
        if (isset($status_counts[$status])) {
            $status_counts[$status]++;
        }
        $total_records++;
    }
}
$effective_present = $status_counts['present'] + $status_counts['late'];
$attendance_rate = $total_records > 0 ? round(($effective_present / $total_records) * 100, 1) : 0;

function status_badge($status) {
    return match($status) {
        'present' => 'bg-emerald-500',
        'absent' => 'bg-red-500',
        'late' => 'bg-amber-400',
        'leave' => 'bg-blue-500',
        'half_absent' => 'bg-orange-500',
        'full_absent' => 'bg-rose-600',
        'awol' => 'bg-red-700',
        'public_holiday' => 'bg-pink-500',
        'weekend' => 'bg-purple-500',
        default => 'bg-gray-200',
    };
}

// Build flat list of all day columns for JS
$all_days = [];
foreach ($weeks as $week) {
    foreach ($week as $d) {
        $all_days[] = $d;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Monthly Attendance</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .att-row.filter-hidden,
        .att-row.page-hidden {
            display: none !important;
        }
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>
<body x-data="attendancePage()" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php
            $page_title = "Monthly Attendance";
            $page_subtitle = "Weekly attendance grid for " . $month_name . ' ' . $selected_year;
            ob_start();
        ?>
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
            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> View
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter">

            <!-- Summary Stats -->
            <section class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-400 flex items-center justify-center">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Active Employees</span>
                            <p class="text-2xl font-extrabold text-white"><?php echo $total_employees; ?></p>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Attendance Rate</span>
                            <p class="text-2xl font-extrabold text-emerald-400"><?php echo $attendance_rate; ?>%</p>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-400"><i class="fa-solid fa-circle text-[8px] mr-1"></i>Present</span>
                    <p class="text-2xl font-bold text-white mt-1"><?php echo $status_counts['present']; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-amber-500"><i class="fa-solid fa-circle text-[8px] mr-1"></i>Late</span>
                    <p class="text-2xl font-bold text-white mt-1"><?php echo $status_counts['late']; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-blue-400"><i class="fa-solid fa-circle text-[8px] mr-1"></i>Leave</span>
                    <p class="text-2xl font-bold text-white mt-1"><?php echo $status_counts['leave']; ?></p>
                </div>
            </section>

            <?php if (empty($employees)): ?>
            <div class="empty-state glass-strong rounded-2xl p-12">
                <svg class="w-24 h-24 mx-auto mb-6 text-zinc-600 dark:text-zinc-700" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="15" y="20" width="70" height="55" rx="6" stroke="currentColor" stroke-width="2" opacity="0.2"/>
                    <line x1="25" y1="32" x2="45" y2="32" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.15"/>
                    <line x1="25" y1="40" x2="55" y2="40" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.15"/>
                    <line x1="25" y1="48" x2="40" y2="48" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.1"/>
                    <rect x="58" y="28" width="18" height="18" rx="3" stroke="currentColor" stroke-width="1.5" opacity="0.15"/>
                    <circle cx="67" cy="37" r="3" fill="currentColor" opacity="0.15"/>
                    <path d="M78 20l6-6 12 12" stroke="url(#grad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.6"/>
                    <circle cx="85" cy="14" r="14" stroke="currentColor" stroke-width="2" opacity="0.15"/>
                    <defs><linearGradient id="grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#e879f9"/></linearGradient></defs>
                </svg>
                <h3 class="text-xl font-bold text-white">No attendance data</h3>
                <p class="text-zinc-400 mt-2 max-w-md mx-auto">Attendance records for <?php echo $month_name . ' ' . $selected_year; ?> will appear here once employees start checking in.</p>
            </div>
            <?php else: ?>

            <!-- Filter & Search Bar -->
            <div class="glass-strong rounded-2xl p-4">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="sm:w-48">
                        <select x-model="departmentFilter" @change="filterEmployees()" class="w-full px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-white/[0.05] border border-slate-200 dark:border-white/[0.08] text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-400 dark:focus:border-blue-500/50 transition-all duration-200">
                            <option value="all">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['department_name']); ?>"><?php echo htmlspecialchars($dept['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="relative flex-1">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-500 text-sm"></i>
                        <input type="text" x-model="searchQuery" @input="filterEmployees()" placeholder="Search by name, ID, position, or department..."
                            class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-slate-100 dark:bg-white/[0.05] border border-slate-200 dark:border-white/[0.08] text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-zinc-500 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-400 dark:focus:border-blue-500/50 transition-all duration-200">
                    </div>
                    <div class="flex items-center gap-2 sm:w-auto">
                        <span class="text-xs text-slate-500 dark:text-zinc-400 whitespace-nowrap">
                            <span x-text="filteredCount"></span> of <span><?php echo count($employees); ?></span> employees
                        </span>
                    </div>
                </div>
            </div>

            <!-- Weekly Attendance Matrix -->
            <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-5 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white text-base"><i class="fa-solid fa-calendar-days text-blue-400 mr-2"></i>Weekly Attendance Matrix</h2>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo $month_name . ' ' . $selected_year; ?></span>
                </div>
                <div class="overflow-x-auto max-h-[50vh] sm:max-h-[60vh] lg:max-h-[600px]">
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
                            <?php foreach ($employees as $eid => $emp):
                                $worked = 0;
                                $total_work_days = 0;
                                foreach ($weeks as $week) {
                                    foreach ($week as $day_ymd) {
                                        $s = $att_map[$eid][$day_ymd] ?? '';
                                        if ($s === 'present' || $s === 'late') $worked++;
                                        if (!empty($s)) $total_work_days++;
                                    }
                                }
                                $emp_rate = $total_work_days > 0 ? round(($worked / $total_work_days) * 100, 1) : 0;
                                $rate_color = $emp_rate >= 90 ? 'text-emerald-400' : ($emp_rate >= 75 ? 'text-amber-400' : 'text-red-400');
                            ?>
                            <tr class="att-row hover:bg-white/[0.02] transition"
                                data-id="<?php echo $eid; ?>"
                                data-name="<?php echo htmlspecialchars(strtolower($emp['name'])); ?>"
                                data-code="<?php echo htmlspecialchars(strtolower($emp['code'])); ?>"
                                data-position="<?php echo htmlspecialchars(strtolower($emp['position'])); ?>"
                                data-department="<?php echo htmlspecialchars($emp['department']); ?>">
                                <td class="px-4 py-2.5 font-medium text-body sticky left-0 bg-body z-10">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full overflow-hidden shrink-0 ring-2 ring-blue-500/10">
                                            <?php if (!empty($emp['profile_photo'])): ?>
                                                <img src="../<?php echo htmlspecialchars($emp['profile_photo']); ?>" class="w-full h-full object-cover" alt="">
                                            <?php else: ?>
                                                <div class="w-full h-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-[9px] font-bold text-white"><?php echo htmlspecialchars(substr($emp['name'], 0, 2)); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="truncate"><?php echo htmlspecialchars($emp['name']); ?></span>
                                    </div>
                                </td>
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
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- No Results -->
            <div x-show="filteredCount === 0" x-cloak class="text-center py-12">
                <i class="fa-solid fa-users-slash text-4xl text-slate-300 dark:text-zinc-600 mb-4"></i>
                <h3 class="text-lg font-bold text-slate-700 dark:text-zinc-300">No attendance records found</h3>
                <p class="text-sm text-slate-500 dark:text-zinc-500 mt-1">Try adjusting your search or filter criteria.</p>
            </div>

            <!-- Pagination -->
            <div x-show="totalPages > 1" x-cloak>
                <div class="glass-strong rounded-2xl p-4">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div class="text-xs text-zinc-500">
                            Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button @click="goToPage(Math.max(1, currentPage - 1))" :disabled="currentPage === 1"
                                class="w-9 h-9 rounded-xl flex items-center justify-center text-xs font-semibold transition-all duration-200"
                                :class="currentPage === 1 ? 'bg-white/[0.03] text-zinc-600 cursor-not-allowed' : 'bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] text-slate-500 dark:text-zinc-400 hover:border-blue-300 dark:hover:border-blue-500/30 hover:text-blue-600 dark:hover:text-blue-400'">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </button>
                            <template x-for="page in visiblePages" :key="page">
                                <button @click="goToPage(page)"
                                    class="w-9 h-9 rounded-xl flex items-center justify-center text-xs font-semibold transition-all duration-200"
                                    :class="page === currentPage ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20' : 'bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] text-slate-500 dark:text-zinc-400 hover:border-blue-300 dark:hover:border-blue-500/30 hover:text-blue-600 dark:hover:text-blue-400'"
                                    x-text="page"></button>
                            </template>
                            <button @click="goToPage(Math.min(totalPages, currentPage + 1))" :disabled="currentPage === totalPages"
                                class="w-9 h-9 rounded-xl flex items-center justify-center text-xs font-semibold transition-all duration-200"
                                :class="currentPage === totalPages ? 'bg-white/[0.03] text-zinc-600 cursor-not-allowed' : 'bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] text-slate-500 dark:text-zinc-400 hover:border-blue-300 dark:hover:border-blue-500/30 hover:text-blue-600 dark:hover:text-blue-400'">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Legend -->
            <section class="flex flex-wrap items-center gap-4 text-xs text-zinc-400">
                <span><span class="inline-block w-3 h-3 rounded-full bg-emerald-500 align-middle mr-1"></span> Present</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-amber-400 align-middle mr-1"></span> Late</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-blue-500 align-middle mr-1"></span> Leave</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-red-700 align-middle mr-1"></span> AWOL</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-rose-600 align-middle mr-1"></span> Full-Day Absent</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-orange-500 align-middle mr-1"></span> Half-Day Absent</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-pink-500 align-middle mr-1"></span> Public Holiday</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-purple-500 align-middle mr-1"></span> Weekend</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-white/10 border border-white/10 align-middle mr-1"></span> No Record</span>
            </section>

            <?php endif; ?>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> HNIN AKARI NWE</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>

    <script>
    function attendancePage() {
        const totalEmps = <?php echo count($employees); ?>;
        return {
            searchQuery: '',
            departmentFilter: 'all',
            currentPage: 1,
            perPage: 5,
            filteredCount: totalEmps,

            get totalPages() {
                return Math.max(1, Math.ceil(this.filteredCount / this.perPage));
            },

            get visiblePages() {
                const pages = [];
                const total = this.totalPages;
                const current = this.currentPage;
                let start = Math.max(1, current - 2);
                let end = Math.min(total, current + 2);
                if (end - start < 4) {
                    if (start === 1) end = Math.min(total, start + 4);
                    else start = Math.max(1, end - 4);
                }
                for (let i = start; i <= end; i++) pages.push(i);
                return pages;
            },

            filterEmployees() {
                const q = this.searchQuery.toLowerCase().trim();
                const dept = this.departmentFilter;
                const rows = document.querySelectorAll('.att-row');
                let visibleCount = 0;

                rows.forEach(row => {
                    const name = row.dataset.name || '';
                    const code = row.dataset.code || '';
                    const position = row.dataset.position || '';
                    const department = row.dataset.department || '';

                    const matchDept = dept === 'all' || department === dept;
                    const matchSearch = !q || name.includes(q) || code.includes(q) || position.includes(q) || department.toLowerCase().includes(q);
                    const visible = matchDept && matchSearch;

                    if (visible) {
                        row.classList.remove('filter-hidden');
                        visibleCount++;
                    } else {
                        row.classList.add('filter-hidden');
                    }
                });

                this.filteredCount = visibleCount;
                this.currentPage = 1;
                this.applyPagination();
            },

            goToPage(page) {
                this.currentPage = page;
                this.applyPagination();
            },

            applyPagination() {
                const rows = Array.from(document.querySelectorAll('.att-row:not(.filter-hidden)'));
                const total = rows.length;
                const start = (this.currentPage - 1) * this.perPage;
                const end = start + this.perPage;

                rows.forEach((row, i) => {
                    if (i >= start && i < end) {
                        row.classList.remove('page-hidden');
                    } else {
                        row.classList.add('page-hidden');
                    }
                });

                // Also hide rows that are filter-hidden
                document.querySelectorAll('.att-row.filter-hidden').forEach(row => {
                    row.classList.add('page-hidden');
                });
            },

            init() {
                // Initial pagination on page load
                this.$nextTick(() => {
                    this.applyPagination();
                });
            }
        };
    }
    </script>
</body>
</html>
