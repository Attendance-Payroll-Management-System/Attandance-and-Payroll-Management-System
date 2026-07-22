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

$message = '';
$message_type = '';

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) {
        $message = "Invalid request.";
        $message_type = "error";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'mark_read') {
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            if ($notif_id > 0) {
                mark_notification_read($conn, $notif_id);
                $message = "Notification marked as read.";
                $message_type = "success";
            }
        } elseif ($action === 'mark_all_read') {
            mark_notifications_read($conn, $employee_id);
            $message = "All notifications marked as read.";
            $message_type = "success";
        }
        
        header('Location: notifications.php');
        exit;
    }
}

// Get notifications
$notifications = [];
$filter_type = $_GET['type'] ?? '';

$sql = "SELECT * FROM notifications WHERE employee_id = ?";
$params = [$employee_id];
$types = 'i';

if (!empty($filter_type)) {
    $sql .= " AND type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

$sql .= " ORDER BY created_at DESC LIMIT 50";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$unread_count = get_unread_count($conn, $employee_id);

// Notification types for filter
$notif_types = [
    '' => 'All',
    'leave_approved' => 'Leave Approved',
    'leave_rejected' => 'Leave Rejected',
    'ot_approved' => 'OT Approved',
    'ot_rejected' => 'OT Rejected',
    'ot_assigned' => 'OT Assigned',
    'payroll_generated' => 'Payroll Generated',
    'payroll_paid' => 'Payroll Paid',
    'attendance_correction' => 'Attendance Correction',
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
    </style>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Notifications"; $page_subtitle = "View your notifications and updates"; include "../includes/topbar.php"; ?>
        
        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full">
            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg border text-sm <?php echo $message_type == 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <form method="GET" class="flex flex-wrap items-center gap-3">
                    <select name="type" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30">
                        <?php foreach ($notif_types as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php echo $filter_type === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold text-sm hover:shadow-lg transition-all">
                        <i class="fa-solid fa-filter text-xs"></i> Filter
                    </button>
                </form>
                
                <?php if ($unread_count > 0): ?>
                <form method="POST" class="inline ml-auto">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-zinc-400 hover:text-white text-sm font-medium transition-all">
                        <i class="fa-solid fa-check-double text-xs mr-1"></i> Mark All Read (<?php echo $unread_count; ?>)
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Notifications List -->
            <div class="glass-strong rounded-2xl overflow-hidden">
                <?php if (empty($notifications)): ?>
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-white/[0.05] flex items-center justify-center">
                            <i class="fa-regular fa-bell-slash text-2xl text-zinc-600"></i>
                        </div>
                        <p class="text-zinc-400 font-medium">No notifications</p>
                        <p class="text-zinc-600 text-sm mt-1">You'll see updates about your leave, overtime, and payroll here.</p>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-white/[0.06]">
                        <?php foreach ($notifications as $notif): ?>
                        <?php
                            $icon = get_notification_icon($notif['type']);
                            $icon_color = get_notification_color($notif['type']);
                            $icon_bg = get_notification_bg_color($notif['type']);
                            $is_unread = !$notif['is_read'];
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
                                            <span class="text-xs font-semibold <?php echo $icon_color; ?> uppercase"><?php echo str_replace('_', ' ', $notif['type']); ?></span>
                                            <p class="text-sm text-zinc-300 mt-1"><?php echo htmlspecialchars($notif['message']); ?></p>
                                            <p class="text-[10px] text-zinc-600 mt-1"><?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?></p>
                                        </div>
                                        
                                        <!-- Actions -->
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <?php if ($notif['link']): ?>
                                            <a href="<?php echo htmlspecialchars($notif['link']); ?>" class="px-3 py-1.5 rounded-lg bg-blue-500/15 text-blue-400 hover:bg-blue-500/25 text-xs font-semibold transition-all">
                                                <i class="fa-solid fa-arrow-right mr-1"></i>View
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($is_unread): ?>
                                            <form method="POST" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                                <button type="submit" class="px-2 py-1.5 rounded-lg bg-white/[0.06] text-zinc-500 hover:text-white text-xs transition-all" title="Mark as read">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                            </form>
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
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>
