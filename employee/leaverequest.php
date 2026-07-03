<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
require_once "../config/notifications.php";

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

set_mmt_timezone();

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];
$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn, $employee_id);

// Check employee status
$is_inactive = validate_employee_active($conn, $employee_id) !== null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_leave'])) {
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = trim($_POST['reason'] ?? '');

    if ($is_inactive) {
        $message = "Your account is inactive. You cannot submit leave requests.";
        $message_type = "error";
    } elseif (empty($leave_type) || empty($start_date) || empty($end_date) || empty($reason)) {
        $message = "Please fill in all fields.";
        $message_type = "error";
    } elseif (strtotime($start_date) < strtotime(mmt_date())) {
        $message = "Start date must be today or a future date.";
        $message_type = "error";
    } elseif (strtotime($end_date) < strtotime($start_date)) {
        $message = "End date must be after start date.";
        $message_type = "error";
    } else {
        // ── Integration: Check if today is within the leave range and employee is checked in ──
        $today = mmt_date();
        if ($start_date <= $today && $end_date >= $today) {
            $today_att = has_checked_in_today($conn, $employee_id, $today);
            if ($today_att && $today_att['check_in'] && $today_att['check_out'] === null) {
                $message = "Please check out before submitting a leave request.";
                $message_type = "error";
            } elseif ($today_att && $today_att['check_in'] === null) {
                // Not checked in today - OK, but check for approved leave on this date
                // No action needed, it's fine
            }
        }

        if (empty($message)) {
            // ── Validate leave request (overlaps, balance, etc.) ──
            $errors = validate_leave_request($conn, $employee_id, $leave_type, $start_date, $end_date);

            if (!empty($errors)) {
                $message = implode(' ', $errors);
                $message_type = "error";
            } else {
                // ── Determine leave duration (full-day / half-day) ──
                $leave_duration = 'full_day';
                $half_day_period = null;

                if ($start_date === $end_date) {
                    // Check if already checked in today to determine half-day
                    $today_att_for_duration = has_checked_in_today($conn, $employee_id, $start_date);
                    if ($today_att_for_duration && $today_att_for_duration['check_in']) {
                        $check_in_time = $today_att_for_duration['check_in'];
                        $work_start = get_company_policy($conn, 'work_start_time', '09:00');
                        $half_day_cutoff = get_company_policy($conn, 'half_day_cutoff_time', '13:00');

                        // If they checked in before cutoff, it's a half-day leave for the afternoon
                        if (strtotime($check_in_time) <= strtotime($half_day_cutoff)) {
                            $leave_duration = 'half_day';
                            $half_day_period = 'afternoon';
                        } else {
                            // Checked in after cutoff - morning half-day
                            $leave_duration = 'half_day';
                            $half_day_period = 'morning';
                        }
                    }
                }

                $stmt = $conn->prepare("INSERT INTO leave_requests (employee_id, leave_type, leave_duration, half_day_period, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('issssss', $employee_id, $leave_type, $leave_duration, $half_day_period, $start_date, $end_date, $reason);
                if ($stmt->execute()) {
                    create_notification($conn, null, 'leave_request', "$employee_name requested $leave_type ($leave_duration)" . ($half_day_period ? " - $half_day_period" : "") . " from $start_date to $end_date", 'leaveApproval.php');
                    header('Location: leaverequest.php');
                    exit;
                } else {
                    $message = "Error submitting leave request.";
                    $message_type = "error";
                }
                $stmt->close();
            }
        }
    }
}

// Get leave balance info
$leave_balances = [];
foreach (['Annual Leave', 'Sick Leave', 'Personal Leave'] as $lt) {
    $leave_balances[$lt] = get_leave_balance($conn, $employee_id, $lt);
}

$leaves = $conn->prepare("SELECT lr.*, e.name as employee_name FROM leave_requests lr JOIN employee e ON lr.employee_id = e.id WHERE lr.employee_id = ? ORDER BY lr.created_at DESC");
$leaves->bind_param('i', $employee_id);
$leaves->execute();
$leave_result = $leaves->get_result();
$leaves->close();

$notifications = get_notifications($conn, $employee_id, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Leave Request</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col h-full overflow-y-auto lg:ml-64">
        <header class="glass-strong px-8 py-4 flex items-center justify-between shrink-0">
            <div class="animate-fade-in-up">
                <h2 class="text-xl font-bold text-white">Leave Request</h2>
                <p class="text-xs text-zinc-400"><?php echo format_mmt(mmt_date(), 'l, F j, Y'); ?> (MMT)</p>
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
                    <i class="fa-solid fa-ban mr-2"></i> Your account is inactive. You cannot submit leave requests.
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Leave Balance Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?php foreach ($leave_balances as $lt => $bal): ?>
                <div class="glass-strong rounded-2xl p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400"><?php echo $lt; ?></span>
                        <span class="text-sm font-bold <?php echo $bal['remaining'] > 0 ? 'text-emerald-400' : 'text-red-400'; ?>">
                            <?php echo max(0, $bal['remaining']); ?> / <?php echo $bal['total_entitled']; ?>
                        </span>
                    </div>
                    <div class="mt-2 progress-bar">
                        <div class="progress-bar-fill <?php echo $bal['remaining'] <= 0 ? 'bg-red-500' : 'bg-emerald-500'; ?>" 
                             style="width: <?php echo $bal['total_entitled'] > 0 ? min(100, (($bal['total_entitled'] - $bal['remaining']) / $bal['total_entitled']) * 100) : 0; ?>%"></div>
                    </div>
                    <div class="flex justify-between text-xs text-zinc-500 mt-1">
                        <span>Taken: <?php echo $bal['total_taken']; ?></span>
                        <span>Pending: <?php echo $bal['total_pending']; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4">New Leave Request</h3>
                    <form method="POST" class="space-y-4 text-zinc-300">
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1">Leave Type</label>
                            <select name="leave_type" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg focus:outline-blue-500 bg-white/[0.06] text-white">
                                <option value="">Select leave type</option>
                                <option value="Annual Leave">Annual Leave</option>
                                <option value="Sick Leave">Sick Leave</option>
                                <option value="Personal Leave">Personal Leave</option>
                                <option value="Maternity Leave">Maternity Leave</option>
                                <option value="Paternity Leave">Paternity Leave</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1">Start Date</label>
                                <input type="date" name="start_date" id="start_date" min="<?php echo mmt_date(); ?>" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500 calendar-picker">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1">End Date</label>
                                <input type="date" name="end_date" id="end_date" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500 calendar-picker">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1">Reason</label>
                            <textarea name="reason" rows="4" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500 resize-none" placeholder="Describe the reason for your leave..."></textarea>
                        </div>
                        <?php if ($is_inactive): ?>
                            <button type="button" disabled class="w-full bg-zinc-600/50 text-zinc-400 font-semibold text-sm px-4 py-3 rounded-lg cursor-not-allowed flex items-center justify-center gap-2">
                                <i class="fa-solid fa-ban"></i> Account Inactive
                            </button>
                        <?php else: ?>
                            <button type="submit" name="submit_leave" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-3 rounded-lg transition">
                                <i class="fa-solid fa-paper-plane"></i> Submit Request
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4">My Leave Requests</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="py-3 font-semibold">Type</th>
                                    <th class="py-3 font-semibold">Duration</th>
                                    <th class="py-3 font-semibold">Dates</th>
                                    <th class="py-3 font-semibold">Reason</th>
                                    <th class="py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                                <?php while ($row = $leave_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="py-3 font-medium text-white"><?php echo htmlspecialchars($row['leave_type']); ?></td>
                                        <td class="py-3 text-xs">
                                            <?php if ($row['leave_duration'] === 'half_day'): ?>
                                                <span class="text-amber-400">Half-Day (<?php echo $row['half_day_period'] ?? 'N/A'; ?>)</span>
                                            <?php else: ?>
                                                <span class="text-blue-400">Full-Day</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3"><?php echo format_mmt($row['start_date'], 'M d') . ' - ' . format_mmt($row['end_date'], 'M d, Y'); ?></td>
                                        <td class="py-3 text-zinc-400 max-w-[150px] truncate"><?php echo htmlspecialchars($row['reason']); ?></td>
                                        <td class="py-3">
                                            <?php
                                            $disp_status = $row['status'];
                                            $stat_class = '';
                                            if ($row['status'] == 'Approved') {
                                                if (strtotime($row['end_date']) < strtotime(mmt_date())) {
                                                    $disp_status = 'Completed';
                                                    $stat_class = 'bg-white/[0.06] text-zinc-400';
                                                } else {
                                                    $stat_class = 'bg-emerald-500/20 text-emerald-400';
                                                }
                                            } elseif ($row['status'] == 'Rejected') {
                                                $stat_class = 'bg-red-500/20 text-red-400';
                                            } else {
                                                $stat_class = 'bg-yellow-500/20 text-yellow-400';
                                            }
                                            ?>
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo $stat_class; ?>">
                                                <?php echo $disp_status; ?>
                                            </span>
                                            <?php if ($row['status'] == 'Rejected' && $row['rejection_reason']): ?>
                                                <span class="block text-[10px] text-red-400 mt-1" title="<?php echo htmlspecialchars($row['rejection_reason']); ?>">Reason provided</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($leave_result->num_rows == 0): ?>
                                    <tr>
                                        <td colspan="5" class="py-6 text-center text-zinc-400">No leave requests yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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
// Initialize flatpickr on calendar inputs
document.addEventListener('DOMContentLoaded', function() {
    flatpickr('.calendar-picker', {
        dateFormat: 'Y-m-d',
        minDate: '<?php echo mmt_date(); ?>',
        locale: { firstDayOfWeek: 1 },
        disableMobile: true
    });
    // Sync end_date >= start_date
    document.getElementById('start_date').addEventListener('change', function() {
        document.getElementById('end_date').min = this.value;
    });
});
</script>
</body>
</html>
