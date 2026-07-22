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

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) {
        $message = 'Invalid CSRF token.';
        $message_type = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'mark_read') {
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            if ($notif_id > 0) {
                mark_notification_read($conn, $notif_id);
                $message = 'Notification marked as read.';
                $message_type = 'success';
            }
        } elseif ($action === 'mark_all_read') {
            mark_notifications_read($conn);
            $message = 'All notifications marked as read.';
            $message_type = 'success';
        } elseif ($action === 'approve_leave') {
            $leave_id = (int)($_POST['leave_id'] ?? 0);
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            if ($leave_id > 0) {
                $stmt = $conn->prepare("UPDATE leave_requests SET status = 'Approved', approved_at = NOW() WHERE id = ? AND status = 'Pending'");
                $stmt->bind_param('i', $leave_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    // Get leave details and create notification
                    $lr = $conn->prepare("SELECT employee_id, leave_type, start_date, end_date FROM leave_requests WHERE id = ?");
                    $lr->bind_param('i', $leave_id);
                    $lr->execute();
                    $leave = $lr->get_result()->fetch_assoc();
                    $lr->close();
                    
                    if ($leave) {
                        create_leave_status_notification($conn, $leave['employee_id'], $leave['leave_type'], $leave['start_date'], $leave['end_date'], 'Approved');
                    }
                    
                    if ($notif_id > 0) mark_notification_read($conn, $notif_id);
                    $message = 'Leave request approved.';
                    $message_type = 'success';
                }
                $stmt->close();
            }
        } elseif ($action === 'reject_leave') {
            $leave_id = (int)($_POST['leave_id'] ?? 0);
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if ($leave_id > 0) {
                $stmt = $conn->prepare("UPDATE leave_requests SET status = 'Rejected', rejection_reason = ? WHERE id = ? AND status = 'Pending'");
                $stmt->bind_param('si', $reason, $leave_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $lr = $conn->prepare("SELECT employee_id, leave_type, start_date, end_date FROM leave_requests WHERE id = ?");
                    $lr->bind_param('i', $leave_id);
                    $lr->execute();
                    $leave = $lr->get_result()->fetch_assoc();
                    $lr->close();
                    
                    if ($leave) {
                        create_leave_status_notification($conn, $leave['employee_id'], $leave['leave_type'], $leave['start_date'], $leave['end_date'], 'Rejected');
                    }
                    
                    if ($notif_id > 0) mark_notification_read($conn, $notif_id);
                    $message = 'Leave request rejected.';
                    $message_type = 'success';
                }
                $stmt->close();
            }
        } elseif ($action === 'approve_ot') {
            $ot_id = (int)($_POST['ot_id'] ?? 0);
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            if ($ot_id > 0) {
                $stmt = $conn->prepare("UPDATE overtime_requests SET status = 'Approved', approved_at = NOW() WHERE id = ? AND status = 'Pending'");
                $stmt->bind_param('i', $ot_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $ot = $conn->prepare("SELECT employee_id, ot_date, total_hours FROM overtime_requests WHERE id = ?");
                    $ot->bind_param('i', $ot_id);
                    $ot->execute();
                    $ot_data = $ot->get_result()->fetch_assoc();
                    $ot->close();
                    
                    if ($ot_data) {
                        create_overtime_status_notification($conn, $ot_data['employee_id'], $ot_data['ot_date'], $ot_data['total_hours'], 'Approved');
                    }
                    
                    if ($notif_id > 0) mark_notification_read($conn, $notif_id);
                    $message = 'Overtime request approved.';
                    $message_type = 'success';
                }
                $stmt->close();
            }
        } elseif ($action === 'reject_ot') {
            $ot_id = (int)($_POST['ot_id'] ?? 0);
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if ($ot_id > 0) {
                $stmt = $conn->prepare("UPDATE overtime_requests SET status = 'Rejected', rejection_reason = ? WHERE id = ? AND status = 'Pending'");
                $stmt->bind_param('si', $reason, $ot_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $ot = $conn->prepare("SELECT employee_id, ot_date, total_hours FROM overtime_requests WHERE id = ?");
                    $ot->bind_param('i', $ot_id);
                    $ot->execute();
                    $ot_data = $ot->get_result()->fetch_assoc();
                    $ot->close();
                    
                    if ($ot_data) {
                        create_overtime_status_notification($conn, $ot_data['employee_id'], $ot_data['ot_date'], $ot_data['total_hours'], 'Rejected');
                    }
                    
                    if ($notif_id > 0) mark_notification_read($conn, $notif_id);
                    $message = 'Overtime request rejected.';
                    $message_type = 'success';
                }
                $stmt->close();
            }
        }
        
        header('Location: notifications.php');
        exit;
    }
}

// Get notifications with related data
$notifications = [];
$filter_type = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT n.*, e.name as emp_name FROM notifications n LEFT JOIN employee e ON n.employee_id = e.id";
$where = [];
$params = [];
$types = '';

if (!empty($filter_type)) {
    $where[] = "n.type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

if (!empty($search)) {
    $where[] = "(n.message LIKE ? OR e.name LIKE ?)";
    $sp = '%' . $search . '%';
    $params[] = $sp;
    $params[] = $sp;
    $types .= 'ss';
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$sql .= " $where_sql ORDER BY n.created_at DESC LIMIT 100";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get pending leaves and OT for action buttons
$pending_leaves = [];
$lr = $conn->query("SELECT id, employee_id, leave_type, start_date, end_date FROM leave_requests WHERE status = 'Pending'");
if ($lr) $pending_leaves = $lr->fetch_all(MYSQLI_ASSOC);

$pending_ots = [];
$otr = $conn->query("SELECT id, employee_id, ot_date, total_hours FROM overtime_requests WHERE status = 'Pending'");
if ($otr) $pending_ots = $otr->fetch_all(MYSQLI_ASSOC);

// Build lookup arrays
$leave_map = [];
foreach ($pending_leaves as $pl) {
    $key = $pl['employee_id'] . '_' . $pl['start_date'];
    $leave_map[$key] = $pl;
}

$ot_map = [];
foreach ($pending_ots as $pot) {
    $ot_map[$pot['employee_id'] . '_' . $pot['ot_date']] = $pot;
}

// Notification types for filter
$notif_types = [
    '' => 'All',
    'leave_request' => 'Leave Requests',
    'leave_approved' => 'Leave Approved',
    'leave_rejected' => 'Leave Rejected',
    'ot_request' => 'OT Requests',
    'ot_approved' => 'OT Approved',
    'ot_rejected' => 'OT Rejected',
    'ot_assigned' => 'OT Assigned',
    'payroll_generated' => 'Payroll Generated',
    'payroll_paid' => 'Payroll Paid',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Notifications</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .notif-item { transition: all 0.2s ease; }
        .notif-item:hover { background: rgba(255,255,255,0.02); }
        .notif-unread { border-left: 3px solid #38bdf8; }
        .action-btn { transition: all 0.2s ease; }
        .action-btn:hover { transform: translateY(-1px); }
    </style>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Notifications";
            $page_subtitle = "View and manage all system notifications";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3">
            <select name="type" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30">
                <?php foreach ($notif_types as $val => $label): ?>
                <option value="<?php echo $val; ?>" <?php echo $filter_type === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search..." class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 w-48">
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold text-sm hover:shadow-lg transition-all">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        
        <main class="flex-1 p-8 overflow-y-auto">
            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/15 border-emerald-500/25' : 'bg-red-500/15 border-red-500/25'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-exclamation text-red-500'; ?>"></i>
                        <p class="font-semibold <?php echo $message_type == 'success' ? 'text-emerald-400' : 'text-red-400'; ?>"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Mark All Read -->
            <?php if (get_unread_count($conn) > 0): ?>
            <div class="mb-4 flex justify-end">
                <form method="POST" class="inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-white/[0.06] border border-white/10 text-zinc-400 hover:text-white text-sm font-medium transition-all">
                        <i class="fa-solid fa-check-double text-xs mr-1"></i> Mark All Read
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Notifications List -->
            <div class="glass-strong rounded-2xl overflow-hidden">
                <?php if (empty($notifications)): ?>
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-white/[0.05] flex items-center justify-center">
                            <i class="fa-regular fa-bell-slash text-2xl text-zinc-600"></i>
                        </div>
                        <p class="text-zinc-400 font-medium">No notifications</p>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-white/[0.06]">
                        <?php foreach ($notifications as $idx => $notif): ?>
                        <?php
                            $icon = get_notification_icon($notif['type']);
                            $icon_color = get_notification_color($notif['type']);
                            $icon_bg = get_notification_bg_color($notif['type']);
                            $is_unread = !$notif['is_read'];
                            
                            // Check if this notification has pending action
                            $has_action = false;
                            $action_type = '';
                            $related_id = 0;
                            
                            if ($notif['type'] === 'leave_request' && preg_match('/requested (\w+) Leave/', $notif['message'], $m)) {
                                // Find pending leave for this employee
                                foreach ($pending_leaves as $pl) {
                                    if ($pl['employee_id'] == $notif['employee_id']) {
                                        $has_action = true;
                                        $action_type = 'leave';
                                        $related_id = $pl['id'];
                                        break;
                                    }
                                }
                            } elseif ($notif['type'] === 'ot_request' && preg_match('/requested OT/', $notif['message'])) {
                                foreach ($pending_ots as $pot) {
                                    if ($pot['employee_id'] == $notif['employee_id']) {
                                        $has_action = true;
                                        $action_type = 'ot';
                                        $related_id = $pot['id'];
                                        break;
                                    }
                                }
                            }
                        ?>
                        <div class="notif-item px-6 py-4 <?php echo $is_unread ? 'notif-unread bg-sky-500/5' : ''; ?>">
                            <div class="flex items-start gap-4">
                                <!-- Icon -->
                                <div class="w-10 h-10 rounded-xl <?php echo $icon_bg; ?> flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid <?php echo $icon; ?> <?php echo $icon_color; ?>"></i>
                                </div>
                                
                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <?php if ($notif['emp_name']): ?>
                                            <span class="text-xs font-semibold text-sky-400"><?php echo htmlspecialchars($notif['emp_name']); ?></span>
                                            <span class="text-xs text-zinc-600 mx-1">&middot;</span>
                                            <?php endif; ?>
                                            <span class="text-xs font-semibold <?php echo $icon_color; ?> uppercase"><?php echo str_replace('_', ' ', $notif['type']); ?></span>
                                            <p class="text-sm text-zinc-300 mt-1"><?php echo htmlspecialchars($notif['message']); ?></p>
                                            <p class="text-[10px] text-zinc-600 mt-1"><?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?></p>
                                        </div>
                                        
                                        <!-- Actions -->
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <?php if ($has_action && $action_type === 'leave'): ?>
                                            <form method="POST" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve_leave">
                                                <input type="hidden" name="leave_id" value="<?php echo $related_id; ?>">
                                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                                <button type="submit" class="action-btn px-3 py-1.5 rounded-lg bg-emerald-500/15 text-emerald-400 hover:bg-emerald-500/25 text-xs font-semibold">
                                                    <i class="fa-solid fa-check mr-1"></i>Approve
                                                </button>
                                            </form>
                                            <form method="POST" class="inline" x-data="{ showReject: false }">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="reject_leave">
                                                <input type="hidden" name="leave_id" value="<?php echo $related_id; ?>">
                                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                                <button type="button" @click="showReject = !showReject" class="action-btn px-3 py-1.5 rounded-lg bg-rose-500/15 text-rose-400 hover:bg-rose-500/25 text-xs font-semibold">
                                                    <i class="fa-solid fa-xmark mr-1"></i>Reject
                                                </button>
                                                <div x-show="showReject" x-transition class="absolute right-0 mt-2 p-3 bg-[#1E293B] rounded-xl shadow-xl border border-white/10 z-50 w-64">
                                                    <input type="text" name="reason" placeholder="Rejection reason..." class="w-full bg-white/[0.06] border border-white/10 text-white text-xs rounded-lg p-2 mb-2 outline-none">
                                                    <div class="flex gap-2">
                                                        <button type="submit" class="flex-1 px-3 py-1.5 rounded-lg bg-rose-500 text-white text-xs font-semibold">Confirm</button>
                                                        <button type="button" @click="showReject = false" class="px-3 py-1.5 rounded-lg bg-white/10 text-zinc-400 text-xs">Cancel</button>
                                                    </div>
                                                </div>
                                            </form>
                                            <?php elseif ($has_action && $action_type === 'ot'): ?>
                                            <form method="POST" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve_ot">
                                                <input type="hidden" name="ot_id" value="<?php echo $related_id; ?>">
                                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                                <button type="submit" class="action-btn px-3 py-1.5 rounded-lg bg-emerald-500/15 text-emerald-400 hover:bg-emerald-500/25 text-xs font-semibold">
                                                    <i class="fa-solid fa-check mr-1"></i>Approve
                                                </button>
                                            </form>
                                            <form method="POST" class="inline" x-data="{ showReject: false }">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="reject_ot">
                                                <input type="hidden" name="ot_id" value="<?php echo $related_id; ?>">
                                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                                <button type="button" @click="showReject = !showReject" class="action-btn px-3 py-1.5 rounded-lg bg-rose-500/15 text-rose-400 hover:bg-rose-500/25 text-xs font-semibold">
                                                    <i class="fa-solid fa-xmark mr-1"></i>Reject
                                                </button>
                                                <div x-show="showReject" x-transition class="absolute right-0 mt-2 p-3 bg-[#1E293B] rounded-xl shadow-xl border border-white/10 z-50 w-64">
                                                    <input type="text" name="reason" placeholder="Rejection reason..." class="w-full bg-white/[0.06] border border-white/10 text-white text-xs rounded-lg p-2 mb-2 outline-none">
                                                    <div class="flex gap-2">
                                                        <button type="submit" class="flex-1 px-3 py-1.5 rounded-lg bg-rose-500 text-white text-xs font-semibold">Confirm</button>
                                                        <button type="button" @click="showReject = false" class="px-3 py-1.5 rounded-lg bg-white/10 text-zinc-400 text-xs">Cancel</button>
                                                    </div>
                                                </div>
                                            </form>
                                            <?php else: ?>
                                            <!-- View Link -->
                                            <?php if ($notif['link']): ?>
                                            <a href="<?php echo htmlspecialchars($notif['link']); ?>" class="action-btn px-3 py-1.5 rounded-lg bg-blue-500/15 text-blue-400 hover:bg-blue-500/25 text-xs font-semibold">
                                                <i class="fa-solid fa-arrow-right mr-1"></i>View
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($is_unread): ?>
                                            <form method="POST" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                                <button type="submit" class="action-btn px-2 py-1.5 rounded-lg bg-white/[0.06] text-zinc-500 hover:text-white text-xs" title="Mark as read">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
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
