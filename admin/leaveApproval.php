<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';
require_once '../config/notifications.php';

set_mmt_timezone();

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn);
$notifications = get_notifications($conn, null, 10);

if (isset($_GET['mark_read'])) {
    mark_notifications_read($conn);
    header('Location: leaveApproval.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) { http_response_code(403); exit('CSRF validation failed.'); }
    $request_id = $_POST['request_id'] ?? 0;
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['approve', 'reject']) && $request_id > 0) {
        $status = $action === 'approve' ? 'Approved' : 'Rejected';

        // Get the leave request details first
        $req_stmt = $conn->prepare("SELECT lr.*, e.name as employee_name FROM leave_requests lr JOIN employee e ON lr.employee_id = e.id WHERE lr.id = ?");
        $req_stmt->bind_param('i', $request_id);
        $req_stmt->execute();
        $leave_req = $req_stmt->get_result()->fetch_assoc();
        $req_stmt->close();

        if ($leave_req) {
            $admin_id = $_SESSION['admin_id'] ?? null;

            // ── Cross-Module Validation before approving ──
            $approval_errors = [];
            if ($status === 'Approved') {
                $start_check = new DateTime($leave_req['start_date']);
                $end_check = new DateTime($leave_req['end_date']);
                $end_check->modify('+1 day');
                $interval_check = new DateInterval('P1D');
                $period_check = new DatePeriod($start_check, $interval_check, $end_check);

                foreach ($period_check as $dt) {
                    $d = $dt->format('Y-m-d');

                    // Check for overtime conflicts
                    $ot_conflict = check_overtime_leave_conflict($conn, $leave_req['employee_id'], $d);
                    if ($ot_conflict) {
                        $approval_errors[] = $ot_conflict;
                        break;
                    }

                    // Check for attendance conflicts (late/partial attendance that would conflict)
                    $att_conflict = check_attendance_leave_conflict($conn, $leave_req['employee_id'], $d);
                    if ($att_conflict) {
                        $approval_errors[] = $att_conflict;
                        break;
                    }

                    // Check for existing attendance with check-in (working day that shouldn't be leave)
                    $existing_att = has_checked_in_today($conn, $leave_req['employee_id'], $d);
                    if ($existing_att && $existing_att['check_in']) {
                        $approval_errors[] = "Employee has already checked in on $d. Cannot approve leave for this date.";
                        break;
                    }
                }
            }

            if (!empty($approval_errors)) {
                $message = implode(' ', $approval_errors);
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("UPDATE leave_requests SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->bind_param('sii', $status, $admin_id, $request_id);
                if ($stmt->execute()) {
                    $message = "Leave request $status successfully.";
                    $message_type = "success";

                    // ── Leave Balance Update ──
                    if ($status === 'Approved') {
                        $start = new DateTime($leave_req['start_date']);
                        $end = new DateTime($leave_req['end_date']);
                        $end->modify('+1 day');
                        $interval = new DateInterval('P1D');
                        $period = new DatePeriod($start, $interval, $end);

                        $leave_days = 0;
                        $half_day_counted = false;
                        foreach ($period as $date) {
                            $d = $date->format('Y-m-d');
                            $day_of_week = (int)$date->format('N');
                            // Count only weekdays (Mon-Fri) as leave days
                            if ($day_of_week <= 5) {
                                if ($leave_req['leave_duration'] === 'half_day') {
                                    if (!$half_day_counted) {
                                        $leave_days += 0.5;
                                        $half_day_counted = true;
                                    }
                                } else {
                                    $leave_days += 1;
                                }
                            }
                            // Mark attendance for each leave date (skip weekends/holidays)
                            if (is_working_day($conn, $d)) {
                                $att_check = $conn->prepare("SELECT id, status FROM attendance WHERE employee_id = ? AND attendance_date = ?");
                                $att_check->bind_param('is', $leave_req['employee_id'], $d);
                                $att_check->execute();
                                $att_result = $att_check->get_result();
                                $existing = $att_result->fetch_assoc();
                                $att_check->close();

                                if ($existing) {
                                    // Only update if existing status is awol or absent (not present/late)
                                    if (in_array($existing['status'], ['awol', 'absent', 'full_absent', 'half_absent', ''])) {
                                        $upd_att = $conn->prepare("UPDATE attendance SET status = 'leave', auto_calculated = 1 WHERE employee_id = ? AND attendance_date = ?");
                                        $upd_att->bind_param('is', $leave_req['employee_id'], $d);
                                        $upd_att->execute();
                                        $upd_att->close();
                                    }
                                } else {
                                    $ins_att = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, status, auto_calculated) VALUES (?, ?, 'leave', 1)");
                                    $ins_att->bind_param('is', $leave_req['employee_id'], $d);
                                    $ins_att->execute();
                                    $ins_att->close();
                                }
                            }
                        }

                        // Update leave balance
                        $year = (int)date('Y', strtotime($leave_req['start_date']));
                        $bal_stmt = $conn->prepare(
                            "INSERT INTO leave_balances (employee_id, leave_type, total_taken, total_pending, year) 
                             VALUES (?, ?, ?, 0, ?)
                             ON DUPLICATE KEY UPDATE total_taken = total_taken + VALUES(total_taken), total_pending = 0"
                        );
                        $bal_stmt->bind_param('isdi', $leave_req['employee_id'], $leave_req['leave_type'], $leave_days, $year);
                        $bal_stmt->execute();
                        $bal_stmt->close();
                    }

                    create_notification($conn, $leave_req['employee_id'], 'leave_' . strtolower($status), "Your {$leave_req['leave_type']} request ({$leave_req['start_date']} to {$leave_req['end_date']}) has been $status.", 'leaverequest.php');
                } else {
                    $message = "Error updating leave request.";
                    $message_type = "error";
                }
                $stmt->close();
            }
        }
    }
}

$result = $conn->query("
    SELECT lr.*, e.name as employee_name, e.employee_code
    FROM leave_requests lr
    JOIN employee e ON lr.employee_id = e.id
    ORDER BY lr.created_at DESC
");
$requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$pending_count = 0;
foreach ($requests as $r) {
    if ($r['status'] == 'Pending') $pending_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Leave Approvals</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Leave Approvals"; $page_subtitle = "Review and manage employee leave requests. Approved leaves will auto-mark attendance."; $page_actions = ($pending_count > 0) ? '<span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-amber-500/20 text-amber-400"><i class="fa-solid fa-clock mr-1"></i> ' . $pending_count . ' pending</span>' : ''; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($requests)): ?>
            <div class="empty-state glass-strong rounded-2xl p-12">
                <svg class="w-24 h-24 mx-auto mb-6 text-zinc-600 dark:text-zinc-700" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="25" y="15" width="50" height="60" rx="6" stroke="currentColor" stroke-width="2" opacity="0.2"/>
                    <rect x="35" y="28" width="12" height="8" rx="2" stroke="currentColor" stroke-width="1.5" opacity="0.15"/>
                    <path d="M28 22h44v8H28z" fill="currentColor" opacity="0.06"/>
                    <line x1="35" y1="45" x2="55" y2="45" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.2"/>
                    <line x1="35" y1="52" x2="50" y2="52" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.15"/>
                    <line x1="35" y1="59" x2="48" y2="59" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.1"/>
                    <path d="M75 66l8-8 12 16" stroke="url(#grad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.6"/>
                    <circle cx="82" cy="55" r="14" stroke="currentColor" stroke-width="2" opacity="0.15"/>
                    <defs><linearGradient id="grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#e879f9"/></linearGradient></defs>
                </svg>
                <h3 class="text-xl font-bold text-white">No leave requests</h3>
                <p class="text-zinc-400 mt-2 max-w-md mx-auto">When employees submit leave requests, they will appear here for your review and approval.</p>
            </div>
            <?php else: ?>
            <div class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4">Leave Type</th>
                                <th class="px-6 py-4">Duration</th>
                                <th class="px-6 py-4">Reason</th>
                                <th class="px-6 py-4">Submitted</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                            <?php foreach ($requests as $req): ?>
                            <tr class="<?php echo $req['status'] == 'Pending' ? 'bg-amber-500/5' : ''; ?> hover:bg-white/[0.02] transition">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white"><?php echo htmlspecialchars($req['employee_name']); ?></div>
                                    <div class="text-xs text-zinc-500"><?php echo htmlspecialchars($req['employee_code']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-white/10 text-zinc-300"><?php echo htmlspecialchars($req['leave_type']); ?></span>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="font-medium"><?php echo format_mmt($req['start_date'], 'M d'); ?> - <?php echo format_mmt($req['end_date'], 'M d, Y'); ?></div>
                                    <?php if ($req['leave_duration'] === 'half_day'): ?>
                                        <span class="text-[10px] text-amber-400">Half-Day (<?php echo $req['half_day_period'] ?? 'N/A'; ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-zinc-400 max-w-[200px] truncate text-sm" title="<?php echo htmlspecialchars($req['reason']); ?>"><?php echo htmlspecialchars($req['reason']); ?></td>
                                <td class="px-6 py-4 text-xs text-zinc-500"><?php echo format_mmt($req['created_at'], 'M d, h:i A'); ?></td>
                                <td class="px-6 py-4">
                                    <?php 
                                    $display_status = $req['status'];
                                    $status_class = '';
                                    if ($req['status'] == 'Approved') {
                                        if (strtotime($req['end_date']) < strtotime(mmt_date())) {
                                            $display_status = 'Completed';
                                            $status_class = 'bg-white/10 text-zinc-400';
                                        } else {
                                            $status_class = 'bg-emerald-500/20 text-emerald-400';
                                        }
                                    } elseif ($req['status'] == 'Rejected') {
                                        $status_class = 'bg-red-500/20 text-red-400';
                                    } else {
                                        $status_class = 'bg-amber-500/20 text-amber-400';
                                    }
                                    ?>
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo $status_class; ?>">
                                        <?php echo $display_status; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <?php if ($req['status'] == 'Pending'): ?>
                                    <form method="POST" class="inline">
                                    <?php echo csrf_field(); ?>
                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                        <button type="submit" name="action" value="approve" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-4 py-2 shadow-sm transition">
                                            <i class="fa-solid fa-check"></i> Approve
                                        </button>
                                        <button type="submit" name="action" value="reject" class="rounded-xl border border-red-500/30 hover:bg-red-500/10 text-red-400 font-semibold text-xs px-4 py-2 transition">
                                            <i class="fa-solid fa-times"></i> Reject
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-xs text-zinc-500">--</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
</body>
</html>
