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

$CHECK_IN_START = '08:30:00';
$WORK_START = '09:00:00';
$WORK_END = '17:00:00';

// Ensure attendance_logs table exists
$conn->query("CREATE TABLE IF NOT EXISTS attendance_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_id INT,
    action ENUM('check_in', 'check_out', 'auto_mark', 'manual_update', 'correction', 'admin_edit') NOT NULL,
    old_value TEXT,
    new_value TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    performed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL
)");

// Ensure attendance_corrections table exists
$conn->query("CREATE TABLE IF NOT EXISTS attendance_corrections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_id INT,
    attendance_date DATE NOT NULL,
    current_check_in TIME,
    current_check_out TIME,
    requested_check_in TIME,
    requested_check_out TIME,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_at DATETIME,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES employee(id) ON DELETE SET NULL
)");

function has_approved_overtime_after($conn, int $employee_id, string $date, string $after_time): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM overtime_requests WHERE employee_id = ? AND ot_date = ? AND status = 'Approved' AND end_time > ?");
    $stmt->bind_param('iss', $employee_id, $date, $after_time);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row['cnt'] ?? 0) > 0;
}

function get_approved_ot_end_time($conn, int $employee_id, string $date): ?string
{
    $stmt = $conn->prepare("SELECT end_time FROM overtime_requests WHERE employee_id = ? AND ot_date = ? AND status = 'Approved' ORDER BY end_time DESC LIMIT 1");
    $stmt->bind_param('is', $employee_id, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['end_time'] : null;
}

function log_attendance_action($conn, $employee_id, $attendance_id, $action, $old_value, $new_value)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare("INSERT INTO attendance_logs (employee_id, attendance_id, action, old_value, new_value, ip_address, user_agent, performed_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $performed_by = $employee_id;
    $stmt->bind_param('iisssssi', $employee_id, $attendance_id, $action, $old_value, $new_value, $ip, $ua, $performed_by);
    $stmt->execute();
    $stmt->close();
}

$status_error = validate_employee_active($conn, $employee_id);
$is_inactive = $status_error !== null;

$date_block_type = is_attendance_blocked_date($conn, $today);
$date_blocked = $date_block_type !== '';
$date_block_message = $date_blocked ? get_attendance_block_message($date_block_type) : '';

// Handle Check In
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
    } elseif (strtotime($current_time) < strtotime($CHECK_IN_START)) {
        $message = "Check-in is not allowed before " . date('h:i A', strtotime($CHECK_IN_START)) . " MMT.";
        $message_type = "error";
    } elseif (strtotime($current_time) >= strtotime($WORK_END)) {
        $message = "Check-in is not allowed after " . date('h:i A', strtotime($WORK_END)) . " MMT.";
        $message_type = "error";
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $is_late = is_late_checkin($current_time) ? 1 : 0;
        $status = $is_late ? 'late' : 'present';

        $existing_att = has_checked_in_today($conn, $employee_id, $today);

        if ($existing_att && $existing_att['check_in']) {
            $message = "You have already checked in today.";
            $message_type = "error";
        } elseif ($existing_att && !$existing_att['check_in']) {
            $stmt = $conn->prepare("UPDATE attendance SET check_in = ?, status = ?, is_late = ?, check_in_ip = ?, check_in_source = 'web', auto_calculated = 1 WHERE id = ?");
            $stmt->bind_param('ssiisi', $current_time, $status, $is_late, $ip, $existing_att['id']);
            if ($stmt->execute()) {
                log_attendance_action($conn, $employee_id, $existing_att['id'], 'check_in', 'AWOL', "$current_time|$status");
                $time_display = date('h:i:s A');
                $message = "Check-in recorded at $time_display. Status: " . ($is_late ? 'Late' : 'Present (On Time)') . ". (AWOL status corrected)";
                $message_type = "success";
                remove_awol_deduction($conn, $employee_id, $today);
            } else {
                $message = "Error recording check-in.";
                $message_type = "error";
            }
            $stmt->close();
        } else {
            $note = $_POST['check_in_note'] ?? '';
            $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in, status, is_late) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('isssi', $employee_id, $today, $current_time, $status, $is_late);
            if ($stmt->execute()) {
                $att_id = $conn->insert_id;
                log_attendance_action($conn, $employee_id, $att_id, 'check_in', null, "$current_time|$status");
                $time_display = date('h:i:s A');
                $message = "Check-in recorded at $time_display. Status: " . ($is_late ? 'Late' : 'Present (On Time)') . ".";
                $message_type = "success";
            } else {
                $message = "Error recording check-in.";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

// Handle Check Out
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_out']) && !$is_inactive) {
    if (!validate_csrf_token()) {
        $message = "Invalid request.";
        $message_type = "error";
    } elseif (has_approved_leave_on_date($conn, $employee_id, $today)) {
        $message = "You have an approved leave for today. Attendance actions are blocked.";
        $message_type = "error";
    } else {
        $check = $conn->prepare("SELECT id, check_in, check_out, status FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $check->bind_param('is', $employee_id, $today);
        $check->execute();
        $result = $check->get_result();
        $att = $result->fetch_assoc();
        $check->close();

        if ($att) {
            if ($att['check_out'] === null) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $check_in_ts = $att['check_in'] ? strtotime($att['check_in']) : 0;
                $check_out_ts = strtotime($current_datetime);
                $total_seconds = max(0, $check_out_ts - $check_in_ts);
                $total_hours = round($total_seconds / 3600, 2);
                $note = $_POST['check_out_note'] ?? '';

                // Early check-out rules
                $check_in_time_only = $att['check_in'] ? date('H:i:s', strtotime($att['check_in'])) : '';
                $check_out_time_only = date('H:i:s', strtotime($current_datetime));
                $is_early_checkout = strtotime($check_out_time_only) < strtotime($WORK_END);

                $stmt = $conn->prepare("UPDATE attendance SET check_out = ?, check_out_ip = ?, check_out_source = 'web', total_working_hours = ?, check_out_note = ? WHERE id = ?");
                $stmt->bind_param('ssdsi', $current_datetime, $ip, $total_hours, $note, $att['id']);
                if ($stmt->execute()) {
                    $stmt->close();
                    $old_status = $att['status'];
                    $new_status = recalculate_attendance_after_checkout($conn, $att['id'], $employee_id, $today, $att['check_in'], $current_datetime, $total_hours);
                    log_attendance_action($conn, $employee_id, $att['id'], 'check_out', "$old_status|{$att['check_in']}", "$new_status|$current_datetime|$total_hours");

                    $time_display = date('h:i:s A');
                    $status_display = get_attendance_status_label($new_status);

                    // Early checkout warnings
                    if ($is_early_checkout && $total_hours < 4) {
                        $message = "Check-out recorded at $time_display ($total_hours hours). WARNING: Early checkout - marked as Absent. Status: $status_display.";
                    } elseif ($is_early_checkout && $total_hours >= 4 && $total_hours < 8) {
                        $message = "Check-out recorded at $time_display ($total_hours hours). NOTE: Early checkout - marked as Half Day. Status: $status_display.";
                    } else {
                        $message = "Check-out recorded at $time_display ($total_hours hours worked). Status: $status_display.";
                    }
                    $message_type = "success";
                } else {
                    $message = "Error recording check-out.";
                    $message_type = "error";
                    $stmt->close();
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

// Handle correction request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_correction'])) {
    $correction_date = $_POST['correction_date'];
    $requested_in = $_POST['requested_check_in'] ?: null;
    $requested_out = $_POST['requested_check_out'] ?: null;
    $reason = trim($_POST['correction_reason'] ?? '');

    if (empty($reason)) {
        $message = "Please provide a reason for the correction.";
        $message_type = "error";
    } else {
        // Get current attendance record for that date
        $current = $conn->prepare("SELECT id, check_in, check_out FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $current->bind_param('is', $employee_id, $correction_date);
        $current->execute();
        $current_att = $current->get_result()->fetch_assoc();
        $current->close();

        if ($current_att) {
            $stmt = $conn->prepare("INSERT INTO attendance_corrections (employee_id, attendance_id, attendance_date, current_check_in, current_check_out, requested_check_in, requested_check_out, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iissssss', $employee_id, $current_att['id'], $correction_date, $current_att['check_in'], $current_att['check_out'], $requested_in, $requested_out, $reason);
            if ($stmt->execute()) {
                $message = "Correction request submitted successfully. Waiting for admin approval.";
                $message_type = "success";
            } else {
                $message = "Error submitting correction request.";
                $message_type = "error";
            }
            $stmt->close();
        } else {
            $message = "No attendance record found for that date.";
            $message_type = "error";
        }
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
    SUM(CASE WHEN status = 'paid_leave' THEN 1 ELSE 0 END) as paid_leave_days,
    SUM(CASE WHEN status = 'unpaid_leave' THEN 1 ELSE 0 END) as unpaid_leave_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days,
    SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as effective_present_days,
    SUM(CASE WHEN status IN ('awol', 'absent', 'full_absent', 'half_absent') THEN 1 ELSE 0 END) as absent_days
FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$present_days = $stats['present_days'] ?? 0;
$leave_days = ($stats['leave_days'] ?? 0) + ($stats['paid_leave_days'] ?? 0) + ($stats['unpaid_leave_days'] ?? 0);
$late_days = $stats['late_days'] ?? 0;
$half_days = $stats['half_days'] ?? 0;
$effective_present = $stats['effective_present_days'] ?? 0;
$absent_days = $stats['absent_days'] ?? 0;

// Monthly OT hours
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as ot_hours FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
$stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$stmt->execute();
$ot_row = $stmt->get_result()->fetch_assoc();
$overtime_hours = $ot_row['ot_hours'] ?? 0;
$stmt->close();

// Check conflicts
$leave_conflict = check_attendance_leave_conflict($conn, $employee_id, $today);
$ot_conflict = check_overtime_attendance_conflict($conn, $employee_id, $today);

// Get pending correction requests count
$corr_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM attendance_corrections WHERE employee_id = ? AND status = 'pending'");
$corr_stmt->bind_param('i', $employee_id);
$corr_stmt->execute();
$pending_corrections = (int)$corr_stmt->get_result()->fetch_assoc()['cnt'];
$corr_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .check-in-btn {
            --btn-gradient: from-blue-600 to-indigo-600;
            --btn-shadow: rgba(59, 130, 246, 0.3);
        }

        .check-out-btn {
            --btn-gradient: from-orange-500 to-rose-500;
            --btn-shadow: rgba(249, 115, 22, 0.3);
        }

        .pulse-ring {
            animation: pulse-ring 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse-ring {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }
    </style>
</head>

<body x-data="{ activeTab: 'today', showCorrection: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Attendance";
        $page_subtitle = format_mmt($today, 'l, F j, Y') . ' (MMT)';
        include "../includes/topbar.php"; ?>

        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full">

            <?php if ($is_inactive): ?>
                <div class="px-4 py-3 rounded-lg border bg-red-500/10 border-red-500/20 text-red-400 text-sm"><i class="fa-solid fa-ban mr-2"></i> Your account is inactive. Please contact your administrator.</div>
            <?php endif; ?>

            <?php if ($date_blocked && !$today_att): ?>
                <div class="px-4 py-3 rounded-lg border bg-purple-500/10 border-purple-500/20 text-purple-400 text-sm"><i class="fa-solid fa-calendar-xmark mr-2"></i> <?php echo htmlspecialchars($date_block_message); ?></div>
            <?php endif; ?>

            <?php if ($leave_conflict): ?>
                <div class="px-4 py-3 rounded-lg border bg-orange-500/10 border-orange-500/20 text-orange-400 text-sm"><i class="fa-solid fa-triangle-exclamation mr-2"></i> <?php echo htmlspecialchars($leave_conflict); ?></div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg border text-sm <?php echo $message_type == 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($pending_corrections > 0): ?>
                <div class="px-4 py-3 rounded-lg border bg-amber-500/10 border-amber-500/20 text-amber-400 text-sm">
                    <i class="fa-solid fa-clock mr-2"></i> You have <?php echo $pending_corrections; ?> pending correction request(s). Waiting for admin approval.
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="flex gap-1 p-1 bg-white/[0.04] rounded-xl w-fit">
                <button @click="activeTab = 'today'" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all" :class="activeTab === 'today' ? 'bg-blue-500/20 text-blue-400 shadow-sm' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]'">
                    <i class="fa-solid fa-calendar-day mr-1.5"></i> Today
                </button>
                <button @click="activeTab = 'history'" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all" :class="activeTab === 'history' ? 'bg-blue-500/20 text-blue-400 shadow-sm' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]'">
                    <i class="fa-solid fa-clock-rotate-left mr-1.5"></i> History
                </button>
                <button @click="activeTab = 'correction'" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all" :class="activeTab === 'correction' ? 'bg-blue-500/20 text-blue-400 shadow-sm' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]'">
                    <i class="fa-solid fa-pen mr-1.5"></i> Request Correction
                </button>
            </div>

            <!-- ═══ TODAY TAB ═══ -->
            <div x-show="activeTab === 'today'" x-transition:enter="transition-all duration-200">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-6">
                    <!-- Today Status -->
                    <div class="glass-strong rounded-2xl p-5 col-span-1 lg:col-span-2">
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-cyan-500/20 text-blue-400 flex items-center justify-center text-lg">
                                <i class="fa-solid fa-fingerprint"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-white">Daily Attendance</h3>
                                <p class="text-xs text-zinc-400">Date: <?php echo format_mmt($today, 'l, F j, Y'); ?></p>
                            </div>
                        </div>

                        <!-- Current time display -->
                        <div class="text-center py-4 mb-4">
                            <div class="text-4xl font-bold text-white tracking-wider font-mono" id="liveClock"><?php echo date('h:i:s A'); ?></div>
                            <p class="text-xs text-zinc-500 mt-1">Myanmar Time (MMT)</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <!-- Status Display -->
                            <div class="bg-white/[0.03] rounded-xl p-4">
                                <p class="text-xs text-zinc-500 mb-1">Today's Status</p>
                                <?php if ($is_inactive): ?>
                                    <div class="text-sm font-semibold text-rose-400"><i class="fa-solid fa-ban mr-1"></i> Inactive</div>
                                <?php elseif ($date_blocked && !$today_att): ?>
                                    <div class="text-sm font-semibold text-purple-400"><i class="fa-solid fa-calendar-xmark mr-1"></i> <?php echo $date_block_type === 'weekend' ? 'Weekend' : 'Holiday'; ?></div>
                                <?php elseif (has_approved_leave_on_date($conn, $employee_id, $today)): ?>
                                    <div class="text-sm font-semibold text-sky-400"><i class="fa-solid fa-plane-departure mr-1"></i> On Approved Leave</div>
                                <?php elseif ($today_att && $today_att['check_in'] && $today_att['check_out']): ?>
                                    <div class="text-sm font-semibold text-emerald-400"><i class="fa-solid fa-check-circle mr-1"></i> Completed</div>
                                    <div class="text-xs text-zinc-500 mt-1">Status: <?php echo get_attendance_status_label($today_att['status']); ?></div>
                                <?php elseif ($today_att && $today_att['check_in']): ?>
                                    <div class="text-sm font-semibold text-amber-400"><i class="fa-solid fa-play mr-1 pulse-ring"></i> Working</div>
                                <?php else: ?>
                                    <div class="text-sm font-semibold text-zinc-400"><i class="fa-regular fa-circle mr-1"></i> Not Started</div>
                                <?php endif; ?>
                            </div>

                            <!-- Time Info -->
                            <div class="bg-white/[0.03] rounded-xl p-4">
                                <p class="text-xs text-zinc-500 mb-1">Current Session</p>
                                <?php if ($today_att && $today_att['check_in']): ?>
                                    <div class="text-sm font-semibold text-white">In: <?php echo date('h:i A', strtotime($today_att['check_in'])); ?></div>
                                    <?php if ($today_att['check_out']): ?>
                                        <div class="text-sm font-semibold text-white">Out: <?php echo date('h:i A', strtotime($today_att['check_out'])); ?></div>
                                        <div class="text-xs text-zinc-400 mt-1">Hours: <?php echo number_format($today_att['total_working_hours'] ?? 0, 1); ?>h</div>
                                    <?php else: ?>
                                        <div class="text-xs text-zinc-400 mt-1" id="elapsedTimer" data-checkin="<?php echo strtotime($today_att['check_in']); ?>">Calculating...</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="text-sm text-zinc-500">No active session</div>
                                    <div class="text-xs text-zinc-600 mt-1">Check-in opens at <?php echo date('h:i A', strtotime($CHECK_IN_START)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="mt-4 flex gap-3">
                            <?php if ($is_inactive): ?>
                                <button disabled class="flex-1 bg-zinc-600/50 text-zinc-400 font-semibold text-sm px-4 py-3 rounded-xl cursor-not-allowed"><i class="fa-solid fa-ban mr-1"></i> Account Inactive</button>
                            <?php elseif ($date_blocked && !$today_att): ?>
                                <div class="flex-1 text-center bg-purple-500/10 text-purple-400 text-sm px-4 py-3 rounded-xl border border-purple-500/20">
                                    <i class="fa-solid fa-calendar-xmark mr-1"></i> No Attendance Required
                                </div>
                            <?php elseif (has_approved_leave_on_date($conn, $employee_id, $today)): ?>
                                <div class="flex-1 text-center bg-sky-500/10 text-sky-400 text-sm px-4 py-3 rounded-xl border border-sky-500/20">
                                    <i class="fa-solid fa-plane-departure mr-1"></i> On Approved Leave
                                </div>
                            <?php elseif (!$today_att || !$today_att['check_in']): ?>
                                <form method="POST" class="flex-1">
                                    <?php echo csrf_field(); ?>
                                    <div class="flex gap-2">
                                        <button type="submit" name="check_in" class="flex-1 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-4 py-3 rounded-xl shadow-lg shadow-blue-500/25 transition-all hover:scale-[1.02]">
                                            <i class="fa-solid fa-arrow-right-to-bracket mr-1"></i> Check In
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if (!$is_inactive && $today_att && $today_att['check_in'] && !$today_att['check_out'] && !has_approved_leave_on_date($conn, $employee_id, $today)): ?>
                                <form method="POST" class="flex-1">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" name="check_out" class="w-full bg-gradient-to-r from-orange-500 to-rose-500 hover:from-orange-600 hover:to-rose-600 text-white font-semibold text-sm px-4 py-3 rounded-xl shadow-lg shadow-orange-500/25 transition-all hover:scale-[1.02]">
                                        <i class="fa-solid fa-arrow-left-from-bracket mr-1"></i> Check Out
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($today_att && $today_att['check_in'] && $today_att['check_out']): ?>
                                <div class="flex-1 text-center bg-emerald-500/10 text-emerald-400 text-sm px-4 py-3 rounded-xl border border-emerald-500/20">
                                    <i class="fa-solid fa-check-circle mr-1"></i> <?php echo get_attendance_status_label($today_att['status']); ?>
                                    (<?php echo number_format($today_att['total_working_hours'] ?? 0, 1); ?>h)
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($date_blocked && !$today_att): ?>
                            <div class="text-xs text-zinc-500 mt-3 p-2 rounded bg-white/[0.04]">
                                <i class="fa-solid fa-info-circle mr-1"></i>
                                <?php echo $date_block_type === 'weekend' ? 'Attendance is not required on weekends.' : 'Attendance is not required on public holidays.'; ?>
                                Full salary is maintained.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Monthly Stats -->
                    <div class="space-y-3">
                        <div class="glass-strong rounded-2xl p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-bold text-white">This Month</h4>
                                <span class="text-xs text-zinc-500"><?php echo date('F'); ?></span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="bg-emerald-500/10 rounded-lg p-2.5 text-center">
                                    <p class="text-lg font-bold text-emerald-400"><?php echo $effective_present; ?></p>
                                    <p class="text-[10px] text-zinc-500">Present</p>
                                </div>
                                <div class="bg-amber-500/10 rounded-lg p-2.5 text-center">
                                    <p class="text-lg font-bold text-amber-400"><?php echo $late_days; ?></p>
                                    <p class="text-[10px] text-zinc-500">Late</p>
                                </div>
                                <div class="bg-teal-500/10 rounded-lg p-2.5 text-center">
                                    <p class="text-lg font-bold text-teal-400"><?php echo $half_days; ?></p>
                                    <p class="text-[10px] text-zinc-500">Half Day</p>
                                </div>
                                <div class="bg-red-500/10 rounded-lg p-2.5 text-center">
                                    <p class="text-lg font-bold text-red-400"><?php echo $absent_days; ?></p>
                                    <p class="text-[10px] text-zinc-500">Absent</p>
                                </div>
                                <div class="bg-purple-500/10 rounded-lg p-2.5 text-center">
                                    <p class="text-lg font-bold text-purple-400"><?php echo number_format($overtime_hours, 1); ?>h</p>
                                    <p class="text-[10px] text-zinc-500">OT Hours</p>
                                </div>
                                <div class="bg-sky-500/10 rounded-lg p-2.5 text-center">
                                    <p class="text-lg font-bold text-sky-400"><?php echo $leave_days; ?></p>
                                    <p class="text-[10px] text-zinc-500">Leave</p>
                                </div>
                            </div>
                        </div>

                        <!-- Working Hours Info -->
                        <div class="glass-strong rounded-2xl p-4">
                            <h4 class="text-sm font-bold text-white mb-2">Working Hours</h4>
                            <div class="space-y-1.5 text-xs text-zinc-500">
                                <div><i class="fa-solid fa-clock mr-1 text-blue-400"></i> Check-in: <?php echo date('h:i A', strtotime($CHECK_IN_START)); ?> - <?php echo date('h:i A', strtotime($WORK_END)); ?></div>
                                <div><i class="fa-solid fa-check-circle mr-1 text-emerald-400"></i> On Time: Before <?php echo date('h:i A', strtotime($WORK_START)); ?></div>
                                <div><i class="fa-solid fa-exclamation-triangle mr-1 text-amber-400"></i> Late: After <?php echo date('h:i A', strtotime($WORK_START)); ?></div>
                                <div><i class="fa-solid fa-business-time mr-1"></i> Required: 8 hours/day</div>
                            </div>
                        </div>

                        <!-- Quick Links -->
                        <div class="glass-strong rounded-2xl p-4">
                            <h4 class="text-sm font-bold text-white mb-2">Quick Links</h4>
                            <div class="flex flex-wrap gap-2">
                                <a href="attendance_summary.php" class="text-xs px-3 py-1.5 bg-blue-500/10 text-blue-400 rounded-lg hover:bg-blue-500/20 transition-all"><i class="fa-solid fa-chart-simple mr-1"></i>Summary</a>
                                <a href="attendanceall.php" class="text-xs px-3 py-1.5 bg-indigo-500/10 text-indigo-400 rounded-lg hover:bg-indigo-500/20 transition-all"><i class="fa-solid fa-calendar-days mr-1"></i>Calendar</a>
                                <button @click="activeTab = 'correction'" class="text-xs px-3 py-1.5 bg-amber-500/10 text-amber-400 rounded-lg hover:bg-amber-500/20 transition-all"><i class="fa-solid fa-pen mr-1"></i>Correction</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ HISTORY TAB ═══ -->
            <div x-show="activeTab === 'history'" x-cloak x-transition:enter="transition-all duration-200">
                <div class="glass-strong rounded-2xl p-5">
                    <h3 class="font-bold text-white mb-4"><i class="fa-solid fa-clock-rotate-left text-blue-400 mr-2"></i>Recent Attendance</h3>
                    <?php
                    $recent = $conn->prepare("SELECT attendance_date, check_in, check_out, status, total_working_hours, is_late FROM attendance WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 15");
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
                                    <tr class="hover:bg-white/[0.02]">
                                        <td class="py-3 font-medium text-white"><?php echo format_mmt($row['attendance_date'], 'M d, Y'); ?></td>
                                        <td class="py-3 font-mono text-xs"><?php echo $row['check_in'] ? date('h:i:s A', strtotime($row['check_in'])) : '-'; ?></td>
                                        <td class="py-3 font-mono text-xs"><?php echo $row['check_out'] ? date('h:i:s A', strtotime($row['check_out'])) : '-'; ?></td>
                                        <td class="py-3 font-mono text-xs"><?php echo $row['total_working_hours'] ? number_format($row['total_working_hours'], 1) . 'h' : '-'; ?></td>
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
                    <div class="mt-4">
                        <a href="attendance_summary.php" class="text-xs text-blue-400 hover:text-blue-300 font-semibold">View Full Summary <i class="fa-solid fa-arrow-right ml-1"></i></a>
                    </div>
                </div>

                <!-- Status Legend -->
                <div class="flex flex-wrap items-center gap-4 text-xs text-zinc-500 glass-strong rounded-2xl p-4 mt-4">
                    <span class="font-semibold text-zinc-400 mr-1">Legend:</span>
                    <span><span class="inline-block w-3 h-3 rounded-full bg-emerald-500/40 align-middle mr-1"></span> Present</span>
                    <span><span class="inline-block w-3 h-3 rounded-full bg-amber-400/40 align-middle mr-1"></span> Late</span>
                    <span><span class="inline-block w-3 h-3 rounded-full bg-teal-500/40 align-middle mr-1"></span> Half Day</span>
                    <span><span class="inline-block w-3 h-3 rounded-full bg-sky-500/40 align-middle mr-1"></span> Paid Leave</span>
                    <span><span class="inline-block w-3 h-3 rounded-full bg-orange-500/40 align-middle mr-1"></span> Unpaid Leave</span>
                    <span><span class="inline-block w-3 h-3 rounded-full bg-red-500/40 align-middle mr-1"></span> Absent</span>
                    <span><span class="inline-block w-3 h-3 rounded-full bg-pink-500/40 align-middle mr-1"></span> Holiday</span>
                    <span><span class="inline-block w-3 h-3 rounded-full bg-purple-500/40 align-middle mr-1"></span> Weekend</span>
                </div>
            </div>

            <!-- ═══ CORRECTION TAB ═══ -->
            <div x-show="activeTab === 'correction'" x-cloak x-transition:enter="transition-all duration-200">
                <div class="glass-strong rounded-2xl p-5">
                    <h3 class="font-bold text-white mb-4"><i class="fa-solid fa-pen-to-square text-amber-400 mr-2"></i>Request Attendance Correction</h3>
                    <p class="text-xs text-zinc-500 mb-4">If your attendance time was recorded incorrectly, submit a correction request for admin approval.</p>

                    <form method="POST" class="max-w-lg">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Date *</label>
                                <select name="correction_date" required class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-500/30">
                                    <option value="">Select a date</option>
                                    <?php
                                    $dates = $conn->prepare("SELECT attendance_date, check_in, check_out FROM attendance WHERE employee_id = ? AND attendance_date <= ? ORDER BY attendance_date DESC LIMIT 30");
                                    $dates->bind_param('is', $employee_id, $today);
                                    $dates->execute();
                                    $date_result = $dates->get_result();
                                    while ($d = $date_result->fetch_assoc()):
                                    ?>
                                        <option value="<?php echo $d['attendance_date']; ?>">
                                            <?php echo date('M d, Y', strtotime($d['attendance_date'])) . ' (In: ' . ($d['check_in'] ? date('h:i A', strtotime($d['check_in'])) : '--') . ' | Out: ' . ($d['check_out'] ? date('h:i A', strtotime($d['check_out'])) : '--') . ')'; ?>
                                        </option>
                                    <?php endwhile;
                                    $dates->close(); ?>
                                </select>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-zinc-300 mb-1">Requested Check In</label>
                                    <input type="time" name="requested_check_in" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-500/30">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-zinc-300 mb-1">Requested Check Out</label>
                                    <input type="time" name="requested_check_out" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-500/30">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Reason for Correction *</label>
                                <textarea name="correction_reason" rows="3" required class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-500/30" placeholder="Explain why the correction is needed..."></textarea>
                            </div>
                            <button type="submit" name="request_correction" class="px-6 py-2.5 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-semibold text-sm rounded-xl shadow-lg shadow-amber-500/25 transition-all hover:scale-105">
                                <i class="fa-solid fa-paper-plane mr-1"></i> Submit Correction Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
    <script>
        // Live clock
        function updateClock() {
            var now = new Date();
            var mmtOffset = 6.5 * 60 * 60 * 1000;
            var utc = now.getTime() + now.getTimezoneOffset() * 60000;
            var mmt = new Date(utc + mmtOffset);
            var h = String(mmt.getHours()).padStart(2, '0');
            var m = String(mmt.getMinutes()).padStart(2, '0');
            var s = String(mmt.getSeconds()).padStart(2, '0');
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            var el = document.getElementById('liveClock');
            if (el) el.textContent = String(h).padStart(2, '0') + ':' + m + ':' + s + ' ' + ampm;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Elapsed timer
        var timerEl = document.getElementById('elapsedTimer');
        if (timerEl) {
            var checkinTs = parseInt(timerEl.getAttribute('data-checkin'));
            if (checkinTs) {
                function updateElapsed() {
                    var now = Date.now();
                    var elapsed = Math.floor((now / 1000) - checkinTs);
                    var h = Math.floor(elapsed / 3600);
                    var m = Math.floor((elapsed % 3600) / 60);
                    timerEl.textContent = 'Elapsed: ' + h + 'h ' + m + 'm';
                }
                setInterval(updateElapsed, 60000);
                updateElapsed();
            }
        }
    </script>
</body>

</html>