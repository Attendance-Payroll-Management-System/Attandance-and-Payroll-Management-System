<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
require_once "../config/notifications.php";

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];
$unread_notifications = get_unread_count($conn, $employee_id);
$notifications = get_notifications($conn, $employee_id, 5);

$current_month = date('Y-m');
$month_start = $current_month . '-01';
$month_end = date('Y-m-t');

// Attendance summary with new statuses
$summary = $conn->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days,
    SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as effective_present,
    SUM(CASE WHEN status IN ('awol', 'absent', 'full_absent', 'half_absent') THEN 1 ELSE 0 END) as absent_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$summary->bind_param('iss', $employee_id, $month_start, $month_end);
$summary->execute();
$stats = $summary->get_result()->fetch_assoc();
$summary->close();

$present_days = $stats['present_days'] ?? 0;
$total_days = $stats['total_days'] ?? 0;
$effective_present = $stats['effective_present'] ?? 0;
$absent_days = $stats['absent_days'] ?? 0;
$late_days = $stats['late_days'] ?? 0;
$present_rate = $total_days > 0 ? round(($effective_present / $total_days) * 100, 1) . '%' : '0%';
$absent_rate = $total_days > 0 ? round(($absent_days / $total_days) * 100, 1) . '%' : '0%';

// OT hours (approved)
$ot = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as ot_hours FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
$ot->bind_param('iss', $employee_id, $month_start, $month_end);
$ot->execute();
$ot_row = $ot->get_result()->fetch_assoc();
$ot_hours = $ot_row['ot_hours'];
$ot->close();

// Attendance logs
$logs = $conn->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date DESC");
$logs->bind_param('iss', $employee_id, $month_start, $month_end);
$logs->execute();
$attendance_logs = $logs->get_result()->fetch_all(MYSQLI_ASSOC);
$logs->close();

// Calendar - get all days of current month with attendance
$calendar_data = [];
$day_count = date('t');
for ($d = 1; $d <= $day_count; $d++) {
    $date = sprintf('%s-%02d', $current_month, $d);
    $day_of_week = date('N', strtotime($date));
    $calendar_data[$d] = [
        'day' => $d,
        'type' => ($day_of_week >= 6) ? 'weekend' : 'none',
        'meta' => ''
    ];
}

$cal_query = $conn->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$cal_query->bind_param('iss', $employee_id, $month_start, $month_end);
$cal_query->execute();
$cal_result = $cal_query->get_result();
while ($row = $cal_result->fetch_assoc()) {
    $d = (int)date('j', strtotime($row['attendance_date']));
    $status = $row['status'];
    if ($status == 'leave') {
        $calendar_data[$d]['type'] = 'leave';
        $calendar_data[$d]['meta'] = 'Leave';
    } elseif ($status == 'late') {
        $calendar_data[$d]['type'] = 'late';
        $calendar_data[$d]['meta'] = ($row['check_in'] ? date('h:i', strtotime($row['check_in'])) : '') . ($row['check_out'] ? ' - ' . date('h:i', strtotime($row['check_out'])) : '') . ' (Late)';
    } elseif ($status == 'awol') {
        $calendar_data[$d]['type'] = 'awol';
        $calendar_data[$d]['meta'] = 'AWOL';
    } elseif ($status == 'public_holiday') {
        $calendar_data[$d]['type'] = 'public_holiday';
        $calendar_data[$d]['meta'] = 'Holiday';
    } elseif ($status == 'weekend') {
        $calendar_data[$d]['type'] = 'weekend';
        $calendar_data[$d]['meta'] = 'Weekend';
    } elseif ($status == 'half_absent') {
        $calendar_data[$d]['type'] = 'active';
        $calendar_data[$d]['meta'] = 'Half-Day';
    } elseif ($status == 'full_absent') {
        $calendar_data[$d]['type'] = 'active';
        $calendar_data[$d]['meta'] = 'Full-Day';
    } elseif ($row['check_in'] && $row['check_out']) {
        $calendar_data[$d]['type'] = 'present';
        $calendar_data[$d]['meta'] = date('h:i', strtotime($row['check_in'])) . ' - ' . date('h:i', strtotime($row['check_out']));
    } elseif ($row['check_in']) {
        $calendar_data[$d]['type'] = 'active';
        $calendar_data[$d]['meta'] = 'Checked in';
    }
}
$cal_query->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance Records</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col h-full overflow-y-auto lg:ml-64">
        <header class="glass-strong px-8 py-4 flex items-center justify-between shrink-0 sticky top-0 z-20">
            <div class="animate-fade-in-up">
                <h2 class="text-xl font-bold text-white">Attendance Records</h2>
                <p class="text-xs text-zinc-400"><?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="flex items-center gap-4">
                <div class="relative" x-data="{ notifOpen: false }">
                    <button @click="notifOpen = !notifOpen" class="relative p-2 text-zinc-400 hover:text-white glass rounded-full transition">
                        <i class="fa-solid fa-bell text-lg"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="absolute -top-1 -right-1 w-5 h-5 bg-rose-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center shadow-lg shadow-rose-500/30 animate-scale-in"><?php echo $unread_notifications; ?></span>
                        <?php endif; ?>
                    </button>
                    <div x-show="notifOpen" @click.outside="notifOpen = false" class="absolute right-0 mt-2 w-96 glass-strong rounded-xl shadow-xl border border-white/10 z-50" style="display: none;">
                        <div class="p-3 border-b border-white/[0.06] flex items-center justify-between">
                            <h4 class="text-sm font-bold text-white"><i class="fa-regular fa-bell mr-1.5 text-sky-400"></i>Notifications</h4>
                            <?php if ($unread_notifications > 0): ?>
                            <a href="mark_notifications_read.php" class="text-[10px] text-sky-400 hover:text-sky-300 font-semibold transition-colors">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <?php if (empty($notifications)): ?>
                                <p class="p-4 text-xs text-zinc-500 text-center">No notifications</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $noti): ?>
                                    <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-white/[0.04] hover:bg-white/[0.02] transition <?php echo !$noti['is_read'] ? 'bg-sky-500/5' : ''; ?>">
                                        <p class="text-xs text-zinc-300"><?php echo htmlspecialchars($noti['message']); ?></p>
                                        <p class="text-[10px] text-zinc-500 mt-1"><?php echo htmlspecialchars($employee_name) . ' - '; ?><?php echo date('M d, h:i A', strtotime($noti['created_at'])); ?></p>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button onclick="toggleTheme()" class="theme-toggle-btn">
                    <i class="fa-solid fa-sun icon-sun text-base"></i>
                    <i class="fa-solid fa-moon icon-moon text-base"></i>
                </button>
                <div class="flex items-center gap-3 border-l border-white/10 pl-4">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-bold shadow-inner"><?php echo strtoupper(substr($employee_name, 0, 2)); ?></div>
                    <div class="text-right">
                        <h4 class="text-sm font-semibold text-white"><?php echo htmlspecialchars($employee_name); ?></h4>
                        <span class="text-xs text-zinc-400">Employee</span>
                    </div>
                </div>
            </div>
        </header>
        <main class="p-8 space-y-8 flex-1 max-w-[1400px] w-full mx-auto">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-cyan-500/20 text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-calendar-check"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Present Days</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?= $effective_present ?><span class="text-sm font-medium text-zinc-400">/ <?= date('t') ?> Days</span></div>
                                <span class="text-xs text-blue-400 font-medium">Includes Late</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-clock"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">OT Hours (Month)</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?= $ot_hours ?></div>
                                <span class="text-xs text-blue-400 font-medium">Approved</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-plane-departure"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Leave Days</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?= $stats['leave_days'] ?? 0 ?></div>
                                <span class="text-xs text-emerald-400 font-medium">This Month</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500/20 to-yellow-500/20 text-amber-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-chart-simple"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Present Rate</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?= $present_rate ?></div>
                                <span class="text-xs text-zinc-400 font-medium">Attendance Rate</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <div class="flex items-center justify-between border-b border-white/[0.06] pb-4 mb-4">
                        <div>
                            <h3 class="font-bold text-white"><i class="fa-solid fa-clock-rotate-left text-blue-400 mr-2"></i>Attendance History</h3>
                            <p class="text-xs text-zinc-400"><?php echo date('F Y'); ?> records</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="py-3 font-semibold">Date</th>
                                    <th class="py-3 font-semibold">Log In</th>
                                    <th class="py-3 font-semibold">Log Out</th>
                                    <th class="py-3 font-semibold">Total Work</th>
                                    <th class="py-3 font-semibold text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                                <?php foreach ($attendance_logs as $log): 
                                    $total = '';
                                    if ($log['check_in'] && $log['check_out']) {
                                        $in_ts = strtotime($log['check_in']);
                                        $out_ts = strtotime($log['check_out']);
                                        $diff = $out_ts - $in_ts;
                                        $total = gmdate('H:i', $diff);
                                    }
                                ?>
                                    <tr class="hover:bg-white/5 transition-colors">
                                        <td class="py-3 font-semibold text-white"><?= date('M d, Y', strtotime($log['attendance_date'])) ?></td>
                                        <td class="py-3 font-mono text-zinc-400"><?= $log['check_in'] ? date('h:i:s A', strtotime($log['check_in'])) : '-' ?></td>
                                        <td class="py-3 font-mono text-zinc-400"><?= $log['check_out'] ? date('h:i:s A', strtotime($log['check_out'])) : '-' ?></td>
                                        <td class="py-3"><?= $total ?: '-' ?></td>
                                        <td class="py-3 text-right">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= get_attendance_status_badge_class($log['status']) ?>">
                                                <?= get_attendance_status_label($log['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($attendance_logs)): ?>
                                <tr><td colspan="5" class="py-8 text-center text-zinc-400">No attendance records for this month.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <div class="flex items-center justify-between border-b border-white/[0.06] pb-4 mb-4">
                        <div>
                            <h3 class="font-bold text-white"><i class="fa-solid fa-calendar-days text-indigo-400 mr-2"></i>Activity Calendar</h3>
                            <p class="text-xs text-zinc-400"><?php echo date('F Y'); ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-7 text-center text-xs font-semibold text-zinc-500 gap-2">
                        <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
                    </div>

                    <div class="grid grid-cols-7 gap-2 mt-3">
                        <?php 
                        $first_day_of_week = date('N', strtotime($current_month . '-01')) - 1;
                        for ($i = 0; $i < $first_day_of_week; $i++): 
                        ?>
                            <div></div>
                        <?php endfor; ?>
                        <?php foreach ($calendar_data as $day): ?>
                            <div class="rounded-2xl border p-3 min-h-[70px] text-left text-xs transition-all duration-150
                                <?= $day['type'] === 'present' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-300 hover:bg-emerald-500/30' : '' ?>
                                <?= $day['type'] === 'leave' ? 'bg-rose-500/20 border-rose-500/30 text-rose-300' : '' ?>
                                <?= $day['type'] === 'active' ? 'bg-blue-600/20 border-blue-400/40 text-blue-300 font-semibold shadow-sm shadow-blue-500/10' : '' ?>
                                <?= $day['type'] === 'weekend' ? 'bg-white/[0.04] border-white/[0.06] text-zinc-500' : '' ?>
                                <?= $day['type'] === 'late' ? 'bg-amber-500/20 border-amber-500/30 text-amber-300' : '' ?>
                                <?= $day['type'] === 'awol' ? 'bg-red-700/20 border-red-700/30 text-red-400' : '' ?>
                                <?= $day['type'] === 'public_holiday' ? 'bg-pink-500/20 border-pink-500/30 text-pink-300' : '' ?>
                                <?= $day['type'] === 'none' ? 'glass-strong text-zinc-400' : '' ?>
                            ">
                                <span class="block text-base font-bold"><?= $day['day'] ?></span>
                                <?php if (!empty($day['meta'])): ?>
                                    <span class="mt-2 block leading-tight tracking-tight text-[10px] opacity-80 truncate"><?= $day['meta'] ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
