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

set_mmt_timezone();
$today = mmt_date();
$current_time = mmt_time();
$current_datetime = mmt_datetime();

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn, $employee_id);
$notifications = get_notifications($conn, $employee_id, 5);

// ─── Working hours constants (MMT) ─────────────────────────────
$WORK_START = '09:00:00';
$WORK_END   = '17:00:00';

// Check if employee has an approved OT request that extends checkout
function has_approved_overtime_after($conn, int $employee_id, string $date, string $after_time): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt FROM overtime_requests 
         WHERE employee_id = ? AND ot_date = ? AND status = 'Approved' AND end_time > ?"
    );
    $stmt->bind_param('iss', $employee_id, $date, $after_time);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row['cnt'] ?? 0) > 0;
}

function get_approved_ot_end_time($conn, int $employee_id, string $date): ?string {
    $stmt = $conn->prepare(
        "SELECT end_time FROM overtime_requests 
         WHERE employee_id = ? AND ot_date = ? AND status = 'Approved'
         ORDER BY end_time DESC LIMIT 1"
    );
    $stmt->bind_param('is', $employee_id, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['end_time'] : null;
}

$status_error = validate_employee_active($conn, $employee_id);
$is_inactive = $status_error !== null;

// ─── Check if today is blockable (weekend / holiday) ──────────
$date_block_type = is_attendance_blocked_date($conn, $today);
$date_blocked = $date_block_type !== '';
$date_block_message = $date_blocked ? get_attendance_block_message($date_block_type) : '';

// ─── Handle Check In ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_in']) && !$is_inactive) {
    if (!validate_csrf_token()) {
        $message = "Invalid request.";
        $message_type = "error";
    } elseif ($date_blocked) {
        $message = $date_block_message;
        $message_type = "error";
    } elseif (has_approved_leave_on_date($conn, $employee_id, $today)) {
        $message = "You have an approved leave for today. Attendance check-in is blocked.";
        $message_type = "error";
    } elseif (has_checked_in_today($conn, $employee_id, $today)) {
        $message = "You have already checked in today.";
        $message_type = "error";
    } elseif (strtotime($current_time) < strtotime($WORK_START)) {
        $message = "Check-in is not allowed before " . date('h:i A', strtotime($WORK_START)) . " MMT. Official working hours start at " . date('h:i A', strtotime($WORK_START)) . ".";
        $message_type = "error";
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $is_late = is_late_checkin($current_time) ? 1 : 0;
        $status = $is_late ? 'late' : 'present';

        $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in, status, is_late, check_in_ip, check_in_source) VALUES (?, ?, ?, ?, ?, ?, 'web')");
        $stmt->bind_param('isssis', $employee_id, $today, $current_time, $status, $is_late, $ip);
        if ($stmt->execute()) {
            $time_display = date('h:i:s A');
            $status_display = $is_late ? ' (Marked as Late)' : '';
            $message = "Check-in recorded at $time_display$status_display.";
            $message_type = "success";
        } else {
            $message = "Error recording check-in.";
            $message_type = "error";
        }
        $stmt->close();
    }
}

// ─── Handle Check Out ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_out']) && !$is_inactive) {
    if (!validate_csrf_token()) {
        $message = "Invalid request.";
        $message_type = "error";
    } elseif (has_approved_leave_on_date($conn, $employee_id, $today)) {
        $message = "You have an approved leave for today. Attendance actions are blocked.";
        $message_type = "error";
    } else {
        $check = $conn->prepare("SELECT id, check_in, check_out FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $check->bind_param('is', $employee_id, $today);
        $check->execute();
        $result = $check->get_result();
        $att = $result->fetch_assoc();
        $check->close();

        if ($att) {
            if ($att['check_out'] === null) {
                // Block normal check-out after 5:00 PM unless approved overtime exists
                $checkout_ts = strtotime($current_datetime);
                $work_end_ts = strtotime($WORK_END);
                $has_ot = has_approved_overtime_after($conn, $employee_id, $today, $WORK_END);

                if ($checkout_ts > $work_end_ts && !$has_ot) {
                    $message = "Normal check-out is not allowed after " . date('h:i A', strtotime($WORK_END)) . " MMT. If you have approved overtime, please wait for your overtime approval.";
                    $message_type = "error";
                } else {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $check_in_ts = $att['check_in'] ? strtotime($att['check_in']) : 0;
                    $check_out_ts = strtotime($current_datetime);
                    $total_seconds = max(0, $check_out_ts - $check_in_ts);
                    $total_hours = round($total_seconds / 3600, 2);

                    $stmt = $conn->prepare("UPDATE attendance SET check_out = ?, check_out_ip = ?, check_out_source = 'web', total_working_hours = ? WHERE id = ?");
                    $stmt->bind_param('ssdi', $current_datetime, $ip, $total_hours, $att['id']);
                    if ($stmt->execute()) {
                        $stmt->close();

                        // Recalculate and update status based on rules
                        $new_status = recalculate_attendance_after_checkout($conn, $att['id'], $employee_id, $today, $att['check_in'], $current_datetime, $total_hours);

                        $time_display = date('h:i:s A');
                        $status_display = get_attendance_status_label($new_status);

                        // Show OT note if applicable
                        $ot_note = '';
                        if ($has_ot) {
                            $ot_end = get_approved_ot_end_time($conn, $employee_id, $today);
                            if ($ot_end) {
                                $ot_note = " (OT approved until " . date('h:i A', strtotime($ot_end)) . ")";
                            }
                        }

                        $message = "Check-out recorded at $time_display ($total_hours hours worked). Status: $status_display.$ot_note";
                        $message_type = "success";
                    } else {
                        $message = "Error recording check-out.";
                        $message_type = "error";
                        $stmt->close();
                    }
                }
            } else {
                $message = "Already checked out today.";
                $message_type = "error";
            }
        } else {
            $message = "Please check in first.";
            $message_type = "error";
        }
    }
}

// Get today's attendance
$today_att = has_checked_in_today($conn, $employee_id, $today);

// Get monthly stats with new statuses
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

$stats = [];
$stmt = $conn->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as effective_present_days,
    SUM(CASE WHEN status IN ('awol', 'absent', 'full_absent', 'half_absent') THEN 1 ELSE 0 END) as absent_days
FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$present_days = $stats['present_days'] ?? 0;
$leave_days = $stats['leave_days'] ?? 0;
$late_days = $stats['late_days'] ?? 0;
$effective_present = $stats['effective_present_days'] ?? 0;
$absent_days = $stats['absent_days'] ?? 0;

// Get monthly OT hours
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as ot_hours FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
$stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$stmt->execute();
$ot_row = $stmt->get_result()->fetch_assoc();
$overtime_hours = $ot_row['ot_hours'] ?? 0;
$stmt->close();

// Check for cross-module conflicts
$leave_conflict = check_attendance_leave_conflict($conn, $employee_id, $today);
$ot_conflict = check_overtime_attendance_conflict($conn, $employee_id, $today);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col h-full overflow-y-auto lg:ml-64">
        <header class="glass-strong px-8 py-4 flex items-center justify-between shrink-0 sticky top-0 z-20">
            <div class="animate-fade-in-up">
                <h2 class="text-xl font-bold text-white">Attendance</h2>
                <p class="text-xs text-zinc-400"><?php echo format_mmt($today, 'l, F j, Y'); ?> (MMT)</p>
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
                            <h4 class="text-sm font-bold text-white"><i class="fa-regular fa-bell mr-1.5 text-violet-400"></i>Notifications</h4>
                            <?php if ($unread_notifications > 0): ?>
                            <a href="mark_notifications_read.php" class="text-[10px] text-violet-400 hover:text-violet-300 font-semibold transition-colors">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <?php if (empty($notifications)): ?>
                                <p class="p-4 text-xs text-zinc-500 text-center">No notifications</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $noti): ?>
                                    <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-white/[0.04] hover:bg-white/[0.02] transition <?php echo !$noti['is_read'] ? 'bg-violet-500/5' : ''; ?>">
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

            <?php if ($is_inactive): ?>
                <div class="px-4 py-3 rounded-lg border bg-red-500/10 border-red-500/20 text-red-400">
                    <i class="fa-solid fa-ban mr-2"></i> Your account is inactive. Please contact your administrator to activate your account.
                </div>
            <?php endif; ?>

            <?php if ($date_blocked && !$today_att): ?>
                <div class="px-4 py-3 rounded-lg border bg-purple-500/10 border-purple-500/20 text-purple-400">
                    <i class="fa-solid fa-calendar-xmark mr-2"></i> <?php echo htmlspecialchars($date_block_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($leave_conflict): ?>
                <div class="px-4 py-3 rounded-lg border bg-orange-500/10 border-orange-500/20 text-orange-400">
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i> <?php echo htmlspecialchars($leave_conflict); ?>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-cyan-500/20 text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-calendar-days"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Present</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?php echo $effective_present; ?></div>
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
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-red-500/20 to-rose-500/20 text-red-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-calendar-xmark"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Absent Days</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?php echo $absent_days; ?></div>
                                <span class="text-xs text-red-400 font-medium">This Month</span>
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
                                <?php elseif ($date_blocked && !$today_att): ?>
                                    <div class="w-full bg-purple-500/10 text-purple-400 font-semibold text-sm px-4 py-3 rounded-lg border border-purple-500/20 text-center">
                                        <i class="fa-solid fa-calendar-xmark"></i> <?php echo $date_block_type === 'weekend' ? 'Weekend' : 'Public Holiday'; ?>
                                    </div>
                                <?php elseif (has_approved_leave_on_date($conn, $employee_id, $today)): ?>
                                    <div class="w-full bg-blue-500/10 text-blue-400 font-semibold text-sm px-4 py-3 rounded-lg border border-blue-500/20 text-center">
                                        <i class="fa-solid fa-plane-departure"></i> On Approved Leave
                                    </div>
                                <?php elseif (!$today_att || !$today_att['check_in']): ?>
                                    <form method="POST" class="flex-1">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" name="check_in" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-3 rounded-lg transition flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-arrow-right-to-bracket"></i> Check In
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$is_inactive && $today_att && $today_att['check_in'] && !$today_att['check_out'] && !has_approved_leave_on_date($conn, $employee_id, $today)): ?>
                                    <form method="POST" class="flex-1">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" name="check_out" class="w-full bg-orange-500 hover:bg-orange-600 text-white font-semibold text-sm px-4 py-3 rounded-lg transition flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-arrow-left-from-bracket"></i> Check Out
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($today_att && $today_att['check_in'] && $today_att['check_out']): ?>
                                    <div class="w-full bg-emerald-500/10 text-emerald-400 font-semibold text-sm px-4 py-3 rounded-lg border border-emerald-500/20 text-center">
                                        <i class="fa-solid fa-check-circle"></i> <?php echo get_attendance_status_label($today_att['status']); ?> (<?php echo number_format($today_att['total_working_hours'] ?? 0, 1); ?>h)
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($date_blocked && !$today_att): ?>
                                <div class="text-xs text-zinc-500 mt-2 p-2 rounded bg-white/[0.04]">
                                    <i class="fa-solid fa-info-circle mr-1"></i>
                                    <?php if ($date_block_type === 'weekend'): ?>
                                        Attendance is not required on weekends (Saturday & Sunday).
                                    <?php else: ?>
                                        Attendance is not required on public holidays.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-xs text-zinc-500 mt-1">
                                <i class="fa-solid fa-clock mr-1"></i> Late threshold: Check-in after <?php echo date('h:i A', strtotime($WORK_START)); ?> MMT is marked Late.
                            </div>
                            <div class="text-xs text-zinc-500 mt-1">
                                <i class="fa-solid fa-business-time mr-1"></i> Working hours: <?php echo date('h:i A', strtotime($WORK_START)); ?> - <?php echo date('h:i A', strtotime($WORK_END)); ?> MMT (8 hours).
                            </div>
                            <?php if (!$today_att || !$today_att['check_out']): ?>
                            <?php
                            $ot_end = has_approved_overtime_after($conn, $employee_id, $today, $WORK_END) ? get_approved_ot_end_time($conn, $employee_id, $today) : null;
                            ?>
                            <?php if ($ot_end): ?>
                            <div class="text-xs text-emerald-400 mt-1">
                                <i class="fa-solid fa-clock mr-1"></i> Approved OT until <?php echo date('h:i A', strtotime($ot_end)); ?> MMT.
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2 group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4">Recent Attendance</h3>
                    <?php
                    $recent = $conn->prepare("SELECT attendance_date, check_in, check_out, status, total_working_hours, is_late FROM attendance WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 10");
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
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo get_attendance_status_badge_class($row['status']); ?>">
                                                <?php echo get_attendance_status_label($row['status']); ?>
                                            </span>
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

            <!-- Status Legend -->
            <div class="flex flex-wrap items-center gap-4 text-xs text-zinc-500 glass-strong rounded-2xl p-4">
                <span class="font-semibold text-zinc-400 mr-1">Status Legend:</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-emerald-500/40 align-middle mr-1"></span> Present</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-amber-400/40 align-middle mr-1"></span> Late</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-blue-500/40 align-middle mr-1"></span> Approved Leave</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-orange-500/40 align-middle mr-1"></span> Half-Day Absent</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-rose-500/40 align-middle mr-1"></span> Full-Day Absent</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-red-600/40 align-middle mr-1"></span> AWOL</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-pink-500/40 align-middle mr-1"></span> Public Holiday</span>
                <span><span class="inline-block w-3 h-3 rounded-full bg-purple-500/40 align-middle mr-1"></span> Weekend</span>
            </div>
        </main>
    </div>
</body>

</html>
