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
                        // Mark attendance for each leave date
                        $att_check = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
                        $att_check->bind_param('is', $leave_req['employee_id'], $d);
                        $att_check->execute();
                        $att_check->store_result();
                        if ($att_check->num_rows === 0) {
                            $ins_att = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, status) VALUES (?, ?, 'leave')");
                            $ins_att->bind_param('is', $leave_req['employee_id'], $d);
                            $ins_att->execute();
                            $ins_att->close();
                        } else {
                            $upd_att = $conn->prepare("UPDATE attendance SET status = 'leave' WHERE employee_id = ? AND attendance_date = ?");
                            $upd_att->bind_param('is', $leave_req['employee_id'], $d);
                            $upd_att->execute();
                            $upd_att->close();
                        }
                        $att_check->close();
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
    <title>AURA HR · Leave Approvals</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Leave Approvals"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8">
            <header class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div class="animate-fade-in-up">
                    <h1 class="text-2xl font-bold text-body tracking-tight">Leave Approvals</h1>
                    <p class="text-sm text-body-secondary mt-1">Review and manage employee leave requests. Approved leaves will auto-mark attendance.</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($pending_count > 0): ?>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-amber-500/20 text-amber-400">
                        <i class="fa-solid fa-clock mr-1"></i> <?php echo $pending_count; ?> pending
                    </span>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

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
                            <?php if (empty($requests)): ?>
                            <tr><td colspan="7" class="px-6 py-12 text-center text-zinc-500">No leave requests found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
