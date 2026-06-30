<?php
session_start();
require_once "../config/db.php";
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

// Attendance summary
$summary = $conn->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days
FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$summary->bind_param('iss', $employee_id, $month_start, $month_end);
$summary->execute();
$stats = $summary->get_result()->fetch_assoc();
$summary->close();

$present_days = $stats['present_days'] ?? 0;
$total_days = $stats['total_days'] ?? 0;
$present_rate = $total_days > 0 ? round(($present_days / $total_days) * 100, 1) . '%' : '0%';

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
    if ($row['status'] == 'leave') {
        $calendar_data[$d]['type'] = 'leave';
        $calendar_data[$d]['meta'] = 'Leave';
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
    <title>Attendance Records - HRMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-sky-50 via-slate-100 to-slate-200 text-slate-900 font-sans antialiased">

    <header class="sticky top-0 z-20 bg-white/95 backdrop-blur-xl border-b border-slate-200/70 shadow-sm">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-4">
            <div class="flex items-center gap-4">
                <div class="rounded-2xl bg-blue-600 px-4 py-2 text-white shadow-lg shadow-blue-500/20">
                    <span class="text-sm font-semibold">HR</span>
                </div>
                <div>
                    <p class="text-lg font-semibold text-slate-900">Enterprise HR</p>
                    <p class="text-sm text-slate-500">Attendance & Overtime Dashboard</p>
                </div>
            </div>
            <nav class="hidden md:flex items-center gap-2 text-sm font-medium text-slate-600">
                <a href="attendance.php" class="rounded-2xl px-3 py-2 transition hover:bg-slate-100">Dashboard</a>
                <a href="attendanceall.php" class="rounded-2xl bg-blue-600 px-3 py-2 text-white shadow-sm shadow-blue-500/20">Attendance</a>
                <a href="leaverequest.php" class="rounded-2xl px-3 py-2 transition hover:bg-slate-100">Leave</a>
                <a href="overtimerequest.php" class="rounded-2xl px-3 py-2 transition hover:bg-slate-100">Overtime</a>
            </nav>
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="relative p-2 text-slate-500 hover:text-slate-700 bg-white rounded-full border border-slate-200">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($unread_notifications > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </button>
                <div x-show="open" @click.outside="open = false" class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-slate-200 z-50" style="display: none;">
                    <div class="p-3 border-b border-slate-100">
                        <h4 class="text-sm font-bold text-slate-800">Notifications</h4>
                    </div>
                    <div class="max-h-64 overflow-y-auto">
                        <?php if (empty($notifications)): ?>
                            <p class="p-4 text-xs text-slate-400 text-center">No notifications</p>
                        <?php else: ?>
                            <?php foreach ($notifications as $noti): ?>
                            <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-slate-50 hover:bg-slate-50 transition <?php echo !$noti['is_read'] ? 'bg-blue-50/50' : ''; ?>">
                                <p class="text-xs text-slate-700"><?php echo htmlspecialchars($noti['message']); ?></p>
                                <p class="text-[10px] text-slate-400 mt-1"><?php echo date('M d, h:i A', strtotime($noti['created_at'])); ?></p>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto p-6 space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-[28px] border border-blue-200/70 bg-blue-600/10 p-5 shadow-sm shadow-blue-500/10 backdrop-blur-sm">
                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-sky-600">Total Working Days</span>
                <div class="mt-3 text-3xl font-semibold text-slate-900">
                    <?= $present_days ?>
                    <span class="text-sm font-medium text-slate-500">/ <?= date('t') ?> Days</span>
                </div>
            </div>
            <div class="rounded-[28px] border border-slate-200/80 bg-white/90 p-5 shadow-sm shadow-slate-300/20">
                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">OT Hours (Month)</span>
                <div class="mt-3 text-3xl font-semibold text-slate-900">
                    <?= $ot_hours ?>
                    <span class="text-sm font-medium text-blue-600">Approved</span>
                </div>
            </div>
            <div class="rounded-[28px] border border-slate-200/80 bg-white/90 p-5 shadow-sm shadow-slate-300/20">
                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">Leave Days</span>
                <div class="mt-3 text-3xl font-semibold text-slate-900">
                    <?= $stats['leave_days'] ?? 0 ?>
                    <span class="text-sm font-medium text-blue-600 block">This Month</span>
                </div>
            </div>
            <div class="rounded-[28px] border border-blue-200/70 bg-blue-600/10 p-5 shadow-sm shadow-blue-500/10 backdrop-blur-sm">
                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-sky-600">Present Rate</span>
                <div class="mt-3 text-3xl font-semibold text-slate-900">
                    <?= $present_rate ?>
                    <span class="text-sm font-medium text-slate-500 block">Attendance Rate</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 rounded-[32px] border border-slate-200/80 bg-white/95 p-6 shadow-xl shadow-slate-400/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Attendance History</h2>
                        <p class="text-sm text-slate-500"><?php echo date('F Y'); ?> records</p>
                    </div>
                </div>

                <div class="overflow-x-auto mt-4">
                    <table class="w-full min-w-[720px] text-left border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 text-xs uppercase tracking-[0.24em]">
                                <th class="py-3 font-semibold">Date</th>
                                <th class="py-3 font-semibold">Log In</th>
                                <th class="py-3 font-semibold">Log Out</th>
                                <th class="py-3 font-semibold">Total Work</th>
                                <th class="py-3 font-semibold text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                            <?php foreach ($attendance_logs as $log): 
                                $total = '';
                                if ($log['check_in'] && $log['check_out']) {
                                    $in_ts = strtotime($log['check_in']);
                                    $out_ts = strtotime($log['check_out']);
                                    $diff = $out_ts - $in_ts;
                                    $total = gmdate('H:i', $diff);
                                }
                            ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="py-4 font-semibold text-slate-900"><?= date('M d, Y', strtotime($log['attendance_date'])) ?></td>
                                    <td class="py-4 font-mono text-slate-600"><?= $log['check_in'] ? date('h:i:s A', strtotime($log['check_in'])) : '-' ?></td>
                                    <td class="py-4 font-mono text-slate-600"><?= $log['check_out'] ? date('h:i:s A', strtotime($log['check_out'])) : '-' ?></td>
                                    <td class="py-4"><?= $total ?: '-' ?></td>
                                    <td class="py-4 text-right">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold 
                                            <?= $log['status'] === 'present' ? 'bg-green-100 text-green-700' : '' ?>
                                            <?= $log['status'] === 'late' ? 'bg-yellow-100 text-yellow-700' : '' ?>
                                            <?= $log['status'] === 'leave' ? 'bg-blue-100 text-blue-700' : '' ?>
                                            <?= $log['status'] === 'absent' ? 'bg-red-100 text-red-700' : '' ?>
                                        ">
                                            <?= ucfirst($log['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($attendance_logs)): ?>
                            <tr><td colspan="5" class="py-8 text-center text-slate-400">No attendance records for this month.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-[32px] border border-slate-200/80 bg-white/95 p-6 shadow-xl shadow-slate-400/5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Activity Calendar</h2>
                        <p class="text-sm text-slate-500"><?php echo date('F Y'); ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-7 text-center text-xs font-semibold text-slate-500 gap-2 mt-4">
                    <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
                </div>

                <div class="grid grid-cols-7 gap-2 mt-3">
                    <?php 
                    $first_day_of_week = date('N', strtotime($current_month . '-01')) - 1; // 0=Mon
                    for ($i = 0; $i < $first_day_of_week; $i++): 
                    ?>
                        <div></div>
                    <?php endfor; ?>
                    <?php foreach ($calendar_data as $day): ?>
                        <div class="rounded-2xl border p-3 min-h-[70px] text-left text-xs transition-all duration-150
                            <?= $day['type'] === 'present' ? 'bg-sky-50 border-sky-200 text-slate-900 hover:bg-sky-100' : '' ?>
                            <?= $day['type'] === 'leave' ? 'bg-rose-50 border-rose-200 text-rose-900' : '' ?>
                            <?= $day['type'] === 'active' ? 'bg-blue-600/10 border-blue-400 text-blue-900 font-semibold shadow-sm shadow-blue-500/10' : '' ?>
                            <?= $day['type'] === 'weekend' ? 'bg-slate-100 border-slate-200 text-slate-400' : '' ?>
                            <?= $day['type'] === 'none' ? 'bg-white border-slate-200 text-slate-400' : '' ?>
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

    <footer class="mt-10 text-center text-sm text-slate-500">
        <p>&copy; <?= date('Y') ?> Enterprise HR Systems</p>
    </footer>
</body>
</html>
