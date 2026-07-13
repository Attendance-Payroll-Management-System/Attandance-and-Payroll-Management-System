<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
require_once "../config/notifications.php";

if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];
set_mmt_timezone();

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)mmt_date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)mmt_date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));
$working_days = get_working_days_in_month($selected_year, $selected_month);

// Get attendance summary
$summary = get_monthly_attendance_summary($conn, $employee_id, $month_start, $month_end);

// Get detailed daily records
$detail_stmt = $conn->prepare(
    "SELECT attendance_date, check_in, check_out, status, total_working_hours, is_late, remarks 
     FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? 
     ORDER BY attendance_date ASC"
);
$detail_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$detail_stmt->execute();
$records = $detail_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$detail_stmt->close();

// Get OT hours
$ot_stmt = $conn->prepare(
    "SELECT COALESCE(SUM(total_hours), 0) as ot_hours FROM overtime_requests 
     WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'"
);
$ot_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$ot_stmt->execute();
$ot_hours = (float)$ot_stmt->get_result()->fetch_assoc()['ot_hours'];
$ot_stmt->close();

// Attendance rate
$effective_present = (int)$summary['present_days'] + (int)$summary['late_days'];
$attendance_rate = $working_days > 0 ? round(($effective_present / $working_days) * 100, 1) : 0;

// Build calendar data
$cal_days = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
$att_map = [];
foreach ($records as $r) {
    $att_map[date('j', strtotime($r['attendance_date']))] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance Summary</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php
        $page_title = "Attendance Summary";
        $page_subtitle = "Your monthly attendance breakdown";
        ob_start();
        ?>
        <form method="GET" class="flex items-center gap-3 glass-strong rounded-xl p-3">
            <div class="flex items-center gap-2">
                <select name="month" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 min-w-[130px]" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <select name="year" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 min-w-[100px]" onchange="this.form.submit()">
                    <?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
        <?php $page_actions = ob_get_clean();
        $sidebar_role = 'employee';
        include "../includes/topbar.php"; ?>
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto page-content w-full">

            <!-- Summary Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center"><i class="fa-solid fa-calendar-check"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Present</span><div class="text-xl font-extrabold text-emerald-400"><?php echo $effective_present; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-2">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-yellow-500/20 text-amber-400 flex items-center justify-center"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Late</span><div class="text-xl font-extrabold text-amber-400"><?php echo (int)$summary['late_days']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-500/20 to-cyan-500/20 text-teal-400 flex items-center justify-center"><i class="fa-solid fa-clock"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Half Day</span><div class="text-xl font-extrabold text-teal-400"><?php echo (int)$summary['half_days']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500/20 to-blue-500/20 text-sky-400 flex items-center justify-center"><i class="fa-solid fa-plane-departure"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Leave</span><div class="text-xl font-extrabold text-sky-400"><?php echo (int)$summary['paid_leave_days'] + (int)$summary['unpaid_leave_days']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500/20 to-rose-500/20 text-red-400 flex items-center justify-center"><i class="fa-solid fa-calendar-xmark"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Absent</span><div class="text-xl font-extrabold text-red-400"><?php echo (int)$summary['absent_days']; ?></div></div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-400 flex items-center justify-center"><i class="fa-solid fa-stopwatch"></i></div>
                        <div><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">OT Hours</span><div class="text-xl font-extrabold text-purple-400"><?php echo number_format($ot_hours, 1); ?>h</div></div>
                    </div>
                </div>
            </div>

            <!-- Attendance Rate -->
            <div class="glass-strong rounded-2xl p-5 mb-6 animate-fade-in-up">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-bold text-white"><i class="fa-solid fa-chart-line text-blue-400 mr-2"></i>Attendance Rate - <?php echo $month_name . ' ' . $selected_year; ?></h3>
                    <span class="text-lg font-extrabold <?php echo $attendance_rate >= 80 ? 'text-emerald-400' : ($attendance_rate >= 50 ? 'text-amber-400' : 'text-red-400'); ?>"><?php echo $attendance_rate; ?>%</span>
                </div>
                <div class="progress-bar"><div class="progress-bar-fill" style="width: <?php echo $attendance_rate; ?>%"></div></div>
                <div class="flex items-center gap-6 mt-3 text-xs text-zinc-500">
                    <span><i class="fa-solid fa-circle text-emerald-500 mr-1" style="font-size:6px"></i>Target: 100%</span>
                    <span><i class="fa-solid fa-circle text-blue-500 mr-1" style="font-size:6px"></i>Required: <?php echo $working_days; ?> working days</span>
                    <span><i class="fa-solid fa-circle text-amber-500 mr-1" style="font-size:6px"></i>Present: <?php echo $effective_present; ?> days</span>
                </div>
            </div>

            <!-- Calendar View -->
            <div class="glass-strong rounded-2xl p-6 mb-6 animate-fade-in-up">
                <h3 class="font-bold text-white mb-4"><i class="fa-solid fa-calendar-days text-blue-400 mr-2"></i>Calendar View</h3>
                <div class="grid grid-cols-7 gap-2 text-center text-xs">
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day): ?>
                    <div class="text-zinc-500 font-bold py-2"><?php echo $day; ?></div>
                    <?php endforeach; ?>
                    <?php
                    $first_day = date('N', strtotime("$selected_year-$selected_month-01"));
                    for ($pad = 1; $pad < $first_day; $pad++):
                        echo '<div></div>';
                    endfor;
                    for ($d = 1; $d <= $cal_days; $d++):
                        $date_str = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $d);
                        $is_wknd = is_weekend($date_str);
                        $record = $att_map[$d] ?? null;
                        $status = $record['status'] ?? null;
                        $bg = 'bg-white/[0.03]';
                        $text = 'text-zinc-500';
                        if ($is_wknd) { $bg = 'bg-purple-500/10'; $text = 'text-purple-400'; }
                        elseif ($status === 'present') { $bg = 'bg-emerald-500/15'; $text = 'text-emerald-400'; }
                        elseif ($status === 'late') { $bg = 'bg-amber-500/15'; $text = 'text-amber-400'; }
                        elseif ($status === 'half_day') { $bg = 'bg-teal-500/15'; $text = 'text-teal-400'; }
                        elseif ($status === 'paid_leave') { $bg = 'bg-sky-500/15'; $text = 'text-sky-400'; }
                        elseif ($status === 'unpaid_leave') { $bg = 'bg-orange-500/15'; $text = 'text-orange-400'; }
                        elseif ($status === 'public_holiday') { $bg = 'bg-pink-500/15'; $text = 'text-pink-400'; }
                        elseif ($status === 'awol' || $status === 'absent' || $status === 'full_absent') { $bg = 'bg-red-500/15'; $text = 'text-red-400'; }
                    ?>
                    <div class="<?php echo $bg; ?> rounded-lg p-2 <?php echo $text; ?> font-medium">
                        <div><?php echo $d; ?></div>
                        <?php if ($record && !$is_wknd): ?>
                        <div class="text-[8px] mt-0.5 opacity-70"><?php echo get_attendance_status_label($status); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <!-- Legend -->
                <div class="flex flex-wrap items-center gap-3 mt-4 text-[10px] text-zinc-500">
                    <span><span class="inline-block w-3 h-3 rounded bg-emerald-500/30 align-middle mr-1"></span>Present</span>
                    <span><span class="inline-block w-3 h-3 rounded bg-amber-500/30 align-middle mr-1"></span>Late</span>
                    <span><span class="inline-block w-3 h-3 rounded bg-teal-500/30 align-middle mr-1"></span>Half Day</span>
                    <span><span class="inline-block w-3 h-3 rounded bg-sky-500/30 align-middle mr-1"></span>Paid Leave</span>
                    <span><span class="inline-block w-3 h-3 rounded bg-orange-500/30 align-middle mr-1"></span>Unpaid Leave</span>
                    <span><span class="inline-block w-3 h-3 rounded bg-red-500/30 align-middle mr-1"></span>Absent</span>
                    <span><span class="inline-block w-3 h-3 rounded bg-pink-500/30 align-middle mr-1"></span>Holiday</span>
                    <span><span class="inline-block w-3 h-3 rounded bg-purple-500/30 align-middle mr-1"></span>Weekend</span>
                </div>
            </div>

            <!-- Daily Records Table -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up">
                <div class="p-5 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500/20 to-cyan-500/10 flex items-center justify-center"><i class="fa-solid fa-list text-sky-500"></i></div>
                        <div>
                            <h3 class="text-base font-bold text-white">Daily Records</h3>
                            <span class="text-xs text-zinc-500"><?php echo count($records); ?> records</span>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Date</th>
                                <th class="px-6 py-4">Day</th>
                                <th class="px-6 py-4 text-center">Check In</th>
                                <th class="px-6 py-4 text-center">Check Out</th>
                                <th class="px-6 py-4 text-center">Hours</th>
                                <th class="px-6 py-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($records)): ?>
                            <tr><td colspan="6" class="px-6 py-12 text-center text-zinc-500">No records for this period.</td></tr>
                            <?php else: foreach ($records as $r): ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-3 font-medium text-white"><?php echo date('M d, Y', strtotime($r['attendance_date'])); ?></td>
                                <td class="px-6 py-3 text-zinc-400"><?php echo date('l', strtotime($r['attendance_date'])); ?></td>
                                <td class="px-6 py-3 text-center font-mono text-sm <?php echo $r['check_in'] ? ($r['is_late'] ? 'text-amber-400' : 'text-emerald-400') : 'text-zinc-600'; ?>">
                                    <?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '—'; ?>
                                </td>
                                <td class="px-6 py-3 text-center font-mono text-sm <?php echo $r['check_out'] ? 'text-rose-400' : 'text-zinc-600'; ?>">
                                    <?php echo $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '—'; ?>
                                </td>
                                <td class="px-6 py-3 text-center font-mono text-sm text-zinc-300">
                                    <?php echo $r['total_working_hours'] ? number_format($r['total_working_hours'], 1) . 'h' : '—'; ?>
                                </td>
                                <td class="px-6 py-3 text-center">
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo get_attendance_status_badge_class($r['status']); ?>">
                                        <?php echo get_attendance_status_label($r['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>
