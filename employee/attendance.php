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

// Always use MMT
set_mmt_timezone();
$today = mmt_date();
$current_time = mmt_time();

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn, $employee_id);
$notifications = get_notifications($conn, $employee_id, 5);

// Check if employee is active before any action
$status_error = validate_employee_active($conn, $employee_id);
$is_inactive = $status_error !== null;

// Handle Check In
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_in']) && !$is_inactive) {
    // Check for approved leave on today
    if (has_approved_leave_on_date($conn, $employee_id, $today)) {
        $message = "You have an approved leave for today. Attendance actions are blocked.";
        $message_type = "error";
    } else {
        $check = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $check->bind_param('is', $employee_id, $today);
        $check->execute();
        $check->store_result();

        if ($check->num_rows == 0) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in, status, check_in_ip, check_in_source) VALUES (?, ?, ?, 'present', ?, 'web')");
            $stmt->bind_param('isss', $employee_id, $today, $current_time, $ip);
            if ($stmt->execute()) {
                $message = "Check-in recorded at " . date('h:i:s A');
                $message_type = "success";
            } else {
                $message = "Error recording check-in.";
                $message_type = "error";
            }
            $stmt->close();
        } else {
            $message = "Already checked in today.";
            $message_type = "error";
        }
        $check->close();
    }
}

// Handle Check Out
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_out']) && !$is_inactive) {
    // Check for approved leave on today
    if (has_approved_leave_on_date($conn, $employee_id, $today)) {
        $message = "You have an approved leave for today. Attendance actions are blocked.";
        $message_type = "error";
    } else {
        $check = $conn->prepare("SELECT id, check_out FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $check->bind_param('is', $employee_id, $today);
        $check->execute();
        $result = $check->get_result();
        $att = $result->fetch_assoc();

        if ($att) {
            if ($att['check_out'] === null) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                // Calculate total working hours
                $check_in_ts = $att['check_in'] ? strtotime($att['check_in']) : 0;
                $check_out_ts = $current_time ? strtotime($current_time) : time();
                $total_seconds = max(0, $check_out_ts - $check_in_ts);
                $total_hours = round($total_seconds / 3600, 2);

                $stmt = $conn->prepare("UPDATE attendance SET check_out = ?, check_out_ip = ?, check_out_source = 'web', total_working_hours = ? WHERE id = ?");
                $stmt->bind_param('ssdi', $current_time, $ip, $total_hours, $att['id']);
                if ($stmt->execute()) {
                    $message = "Check-out recorded at " . date('h:i:s A') . " (" . number_format($total_hours, 2) . " hours worked)";
                    $message_type = "success";
                } else {
                    $message = "Error recording check-out.";
                    $message_type = "error";
                }
                $stmt->close();
            } else {
                $message = "Already checked out today.";
                $message_type = "error";
            }
        } else {
            $message = "Please check in first.";
            $message_type = "error";
        }
        $check->close();
    }
}

// Get today's attendance
$today_att = has_checked_in_today($conn, $employee_id, $today);

// Get monthly stats
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

$stats = [];
$stmt = $conn->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$present_days = $stats['present_days'] ?? 0;
$leave_days = $stats['leave_days'] ?? 0;
$late_days = $stats['late_days'] ?? 0;

// Get monthly OT hours
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as ot_hours FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
$stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$stmt->execute();
$ot_row = $stmt->get_result()->fetch_assoc();
$overtime_hours = $ot_row['ot_hours'] ?? 0;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Attendance</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col h-full overflow-y-auto lg:ml-64">
        <header class="glass-strong px-8 py-4 flex items-center justify-between shrink-0">
            <div class="animate-fade-in-up">
                <h2 class="text-xl font-bold text-white">Attendance</h2>
                <p class="text-xs text-zinc-400"><?php echo format_mmt($today, 'l, F j, Y'); ?> (MMT)</p>
            </div>
            <div class="flex items-center gap-4">
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="relative p-2 text-zinc-400 hover:text-white bg-white/10 rounded-full">
                        <i class="fa-solid fa-bell text-lg"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"><?php echo $unread_notifications; ?></span>
                        <?php endif; ?>
                    </button>
                    <div x-show="open" @click.outside="open = false" class="absolute right-0 mt-2 w-80 glass-strong rounded-xl shadow-xl z-50" style="display: none;">
                        <div class="p-3 border-b border-white/[0.06]">
                            <h4 class="text-sm font-bold text-white">Notifications</h4>
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            <?php if (empty($notifications)): ?>
                                <p class="p-4 text-xs text-zinc-500 text-center">No notifications</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $noti): ?>
                                    <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-white/[0.06] hover:bg-white/5 transition <?php echo !$noti['is_read'] ? 'bg-blue-500/10' : ''; ?>">
                                        <p class="text-xs text-zinc-300"><?php echo htmlspecialchars($noti['message']); ?></p>
                                        <p class="text-[10px] text-zinc-500 mt-1"><?php echo format_mmt($noti['created_at'], 'M d, h:i A'); ?></p>
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
            <?php if ($is_inactive): ?>
                <div class="px-4 py-3 rounded-lg border bg-red-500/10 border-red-500/20 text-red-400">
                    <i class="fa-solid fa-ban mr-2"></i> Your account is inactive. Please contact your administrator to activate your account.
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-cyan-500/20 text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-calendar-days"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Present Days</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?php echo $present_days; ?></div>
                                <span class="text-xs text-blue-400 font-medium">This Month</span>
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
                                <div class="text-2xl font-bold text-white mt-0.5"><?php echo $leave_days; ?></div>
                                <span class="text-xs text-emerald-400 font-medium">This Month</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500/20 to-yellow-500/20 text-amber-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-clock"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Late Days</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?php echo $late_days; ?></div>
                                <span class="text-xs text-amber-400 font-medium">This Month</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-clock"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Today</span>
                                <div class="text-2xl font-bold text-white mt-0.5">
                                    <?php
                                    if ($is_inactive) echo '--';
                                    elseif ($today_att && $today_att['check_in'] && $today_att['check_out']) echo 'Done';
                                    elseif ($today_att && $today_att['check_in']) echo 'Working';
                                    else echo '--';
                                    ?>
                                </div>
                                <span class="text-xs text-zinc-400 font-medium">
                                    <?php
                                    if ($today_att && $today_att['check_in']) {
                                        echo 'In: ' . date('h:i A', strtotime($today_att['check_in']));
                                        if ($today_att['check_out']) echo ' | Out: ' . date('h:i A', strtotime($today_att['check_out']));
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div>
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-cyan-500/20 text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-fingerprint"></i></div>
                            <div>
                                <h3 class="font-bold text-white">Daily Attendance</h3>
                                <p class="text-xs text-zinc-400">Mark your check in and check out</p>
                            </div>
                        </div>
                        <div class="space-y-3 text-zinc-300">
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1">Date (MMT)</label>
                                <input type="date" value="<?php echo $today; ?>" disabled class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white">
                            </div>
                            <div class="flex gap-3">
                                <?php if ($is_inactive): ?>
                                    <button disabled class="w-full bg-zinc-600/50 text-zinc-400 font-semibold text-sm px-4 py-3 rounded-lg cursor-not-allowed flex items-center justify-center gap-2">
                                        <i class="fa-solid fa-ban"></i> Account Inactive
                                    </button>
                                <?php elseif (has_approved_leave_on_date($conn, $employee_id, $today)): ?>
                                    <div class="w-full bg-blue-500/10 text-blue-400 font-semibold text-sm px-4 py-3 rounded-lg border border-blue-500/20 text-center">
                                        <i class="fa-solid fa-plane-departure"></i> On Approved Leave
                                    </div>
                                <?php elseif (!$today_att || !$today_att['check_in']): ?>
                                    <form method="POST" class="flex-1">
                                        <button type="submit" name="check_in" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-3 rounded-lg transition flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-arrow-right-to-bracket"></i> Check In
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$is_inactive && $today_att && $today_att['check_in'] && !$today_att['check_out'] && !has_approved_leave_on_date($conn, $employee_id, $today)): ?>
                                    <form method="POST" class="flex-1">
                                        <button type="submit" name="check_out" class="w-full bg-orange-500 hover:bg-orange-600 text-white font-semibold text-sm px-4 py-3 rounded-lg transition flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-arrow-left-from-bracket"></i> Check Out
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($today_att && $today_att['check_in'] && $today_att['check_out']): ?>
                                    <div class="w-full bg-emerald-500/10 text-emerald-400 font-semibold text-sm px-4 py-3 rounded-lg border border-emerald-500/20 text-center">
                                        <i class="fa-solid fa-check-circle"></i> Completed (<?php echo number_format($today_att['total_working_hours'] ?? 0, 1); ?>h)
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2 group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4">Recent Attendance</h3>
                    <?php
                    $recent = $conn->prepare("SELECT attendance_date, check_in, check_out, status, total_working_hours FROM attendance WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 10");
                    $recent->bind_param('i', $employee_id);
                    $recent->execute();
                    $recent_result = $recent->get_result();
                    ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="py-3 font-semibold">Date</th>
                                    <th class="py-3 font-semibold">Check In</th>
                                    <th class="py-3 font-semibold">Check Out</th>
                                    <th class="py-3 font-semibold">Hours</th>
                                    <th class="py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                                <?php while ($row = $recent_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="py-3 font-medium text-white"><?php echo format_mmt($row['attendance_date'], 'M d, Y'); ?></td>
                                        <td class="py-3 font-mono"><?php echo $row['check_in'] ? date('h:i:s A', strtotime($row['check_in'])) : '-'; ?></td>
                                        <td class="py-3 font-mono"><?php echo $row['check_out'] ? date('h:i:s A', strtotime($row['check_out'])) : '-'; ?></td>
                                        <td class="py-3 font-mono"><?php echo $row['total_working_hours'] ? number_format($row['total_working_hours'], 1) . 'h' : '-'; ?></td>
                                        <td class="py-3">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold
                                            <?php echo $row['status'] == 'present' ? 'bg-emerald-500/20 text-emerald-400' : ''; ?>
                                            <?php echo $row['status'] == 'late' ? 'bg-yellow-500/20 text-yellow-400' : ''; ?>
                                            <?php echo $row['status'] == 'leave' ? 'bg-blue-500/20 text-blue-400' : ''; ?>
                                            <?php echo $row['status'] == 'absent' ? 'bg-red-500/20 text-red-400' : ''; ?>
                                        "><?php echo ucfirst($row['status']); ?></span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($recent_result->num_rows == 0): ?>
                                    <tr>
                                        <td colspan="5" class="py-6 text-center text-zinc-500">No attendance records yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php $recent->close(); ?>
                </div>
            </div>
        </main>
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