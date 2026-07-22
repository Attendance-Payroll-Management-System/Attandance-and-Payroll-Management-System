<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';
require_once '../config/notifications.php';

set_mmt_timezone();

$admin_id = $_SESSION['admin_id'] ?? 0;
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn);
$notifications = get_notifications($conn, null, 10);

$assignment_id = (int)($_GET['id'] ?? 0);
if ($assignment_id <= 0) {
    header('Location: assignment_list.php');
    exit;
}

$has_table = $conn->query("SHOW TABLES LIKE 'overtime_assignments'")->num_rows > 0;
if (!$has_table) {
    header('Location: assignment_list.php');
    exit;
}

$assignment = get_overtime_assignment_detail($conn, $assignment_id);
if (!$assignment) {
    header('Location: assignment_list.php');
    exit;
}

// Handle cancel action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_assignment'])) {
    if (!validate_csrf_token()) {
        $message = 'Invalid request.';
        $message_type = 'error';
    } else {
        $result = cancel_overtime_assignment($conn, $assignment_id);
        if ($result) {
            $message = 'Assignment cancelled successfully.';
            $message_type = 'success';
            log_activity($conn, $admin_id, 'overtime_assignment_cancelled', "Cancelled assignment #{$assignment['assignment_code']}");
            // Refresh
            $assignment = get_overtime_assignment_detail($conn, $assignment_id);
        } else {
            $message = 'Error cancelling assignment.';
            $message_type = 'error';
        }
    }
}

// Status badge helper
function get_assignment_status_badge(string $status): string {
    return match($status) {
        'Assigned' => 'bg-blue-500/20 text-blue-400',
        'Accepted' => 'bg-emerald-500/20 text-emerald-400',
        'Rejected' => 'bg-red-500/20 text-red-400',
        'Completed' => 'bg-purple-500/20 text-purple-400',
        'Cancelled' => 'bg-zinc-500/20 text-zinc-400',
        default => 'bg-white/10 text-zinc-300',
    };
}

function get_assignment_status_icon(string $status): string {
    return match($status) {
        'Assigned' => 'fa-clipboard-list',
        'Accepted' => 'fa-circle-check',
        'Rejected' => 'fa-circle-xmark',
        'Completed' => 'fa-flag-checkered',
        'Cancelled' => 'fa-ban',
        default => 'fa-circle',
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Assignment #<?php echo htmlspecialchars($assignment['assignment_code']); ?></title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Assignment #" . htmlspecialchars($assignment['assignment_code']);
            $page_subtitle = "Overtime assignment details.";
            ob_start();
        ?>
        <div class="flex items-center gap-2">
            <a href="assignment_list.php" class="rounded-xl bg-white/[0.06] hover:bg-white/[0.1] text-zinc-400 hover:text-white px-4 py-2 transition text-sm font-semibold">
                <i class="fa-solid fa-arrow-left mr-1"></i> Back
            </a>
            <?php if ($assignment['status'] === 'Assigned'): ?>
            <form method="POST" class="inline" onsubmit="return confirm('Cancel this assignment? This will cancel all related overtime records.');">
                <?php echo csrf_field(); ?>
                <button type="submit" name="cancel_assignment" class="rounded-xl border border-red-500/30 hover:bg-red-500/10 text-red-400 font-semibold text-sm px-4 py-2 transition flex items-center gap-2">
                    <i class="fa-solid fa-ban"></i> Cancel Assignment
                </button>
            </form>
            <?php endif; ?>
        </div>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Assignment Header -->
            <section class="mb-6">
                <div class="card-hover glass-strong rounded-2xl p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <div class="flex items-center gap-3 mb-2">
                                <h2 class="text-xl font-bold text-white"><?php echo htmlspecialchars($assignment['assignment_code']); ?></h2>
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo get_assignment_status_badge($assignment['status']); ?>">
                                    <i class="fa-solid <?php echo get_assignment_status_icon($assignment['status']); ?> mr-1"></i>
                                    <?php echo $assignment['status']; ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-2 text-sm text-zinc-400">
                                <span class="inline-flex items-center gap-1 <?php echo $assignment['assignment_type'] === 'department' ? 'text-cyan-400' : 'text-purple-400'; ?>">
                                    <i class="fa-solid <?php echo $assignment['assignment_type'] === 'department' ? 'fa-building' : 'fa-user'; ?>"></i>
                                    <?php echo ucfirst($assignment['assignment_type']); ?> Assignment
                                </span>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-zinc-500">Created</p>
                            <p class="text-sm text-white"><?php echo date('M d, Y h:i A', strtotime($assignment['created_at'])); ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 pt-4 border-t border-white/[0.06]">
                        <div>
                            <p class="text-xs text-zinc-500 mb-1">Target</p>
                            <p class="text-sm font-semibold text-white">
                                <?php if ($assignment['assignment_type'] === 'department'): ?>
                                    <?php echo htmlspecialchars($assignment['department_name'] ?? 'N/A'); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($assignment['employee_name'] ?? 'N/A'); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-500 mb-1">OT Date</p>
                            <p class="text-sm font-semibold text-white"><?php echo date('M d, Y', strtotime($assignment['ot_date'])); ?></p>
                            <p class="text-[10px] text-zinc-500"><?php echo date('l', strtotime($assignment['ot_date'])); ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-500 mb-1">Time</p>
                            <p class="text-sm font-semibold text-white">
                                <?php echo date('h:i A', strtotime($assignment['start_time'])); ?> - <?php echo date('h:i A', strtotime($assignment['end_time'])); ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-500 mb-1">Total Hours</p>
                            <p class="text-sm font-bold text-white"><?php echo $assignment['total_hours']; ?>h</p>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-500 mb-1">OT Type</p>
                            <div><?php echo get_overtime_type_badge($assignment['ot_type']); ?></div>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-500 mb-1">Assigned By</p>
                            <p class="text-sm font-semibold text-white"><?php echo htmlspecialchars($assignment['assigned_by_name'] ?? 'N/A'); ?></p>
                            <p class="text-[10px] text-zinc-500"><?php echo htmlspecialchars($assignment['assigned_by_position'] ?? ''); ?></p>
                        </div>
                    </div>

                    <?php if (!empty($assignment['reason'])): ?>
                    <div class="mt-4 pt-4 border-t border-white/[0.06]">
                        <p class="text-xs text-zinc-500 mb-1">Reason</p>
                        <p class="text-sm text-zinc-300"><?php echo nl2br(htmlspecialchars($assignment['reason'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Employees Table -->
            <section>
                <div class="card-hover glass-strong rounded-2xl overflow-hidden">
                    <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                        <h3 class="font-bold text-white text-lg"><i class="fa-solid fa-users text-blue-400 mr-2"></i>Assigned Employees</h3>
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo count($assignment['employees']); ?> employees</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="px-6 py-4">#</th>
                                    <th class="px-6 py-4">Employee</th>
                                    <th class="px-6 py-4">Department</th>
                                    <th class="px-6 py-4">Position</th>
                                    <th class="px-6 py-4">OT Rate</th>
                                    <th class="px-6 py-4">OT Pay</th>
                                    <th class="px-6 py-4">Status</th>
                                    <th class="px-6 py-4">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06]">
                                <?php if (empty($assignment['employees'])): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-12 text-center text-zinc-500">No employee records found.</td>
                                </tr>
                                <?php else: ?>
                                    <?php $rank = 1; foreach ($assignment['employees'] as $emp): ?>
                                    <?php
                                        $is_ineligible = !empty($emp['validation_notes']);
                                        $emp_status = $emp['status'] ?? 'Assigned';
                                    ?>
                                    <tr class="<?php echo $is_ineligible ? 'bg-red-500/5' : ''; ?> hover:bg-white/[0.02] transition">
                                        <td class="px-6 py-4 font-bold text-zinc-500"><?php echo $rank++; ?></td>
                                        <td class="px-6 py-4">
                                            <div class="font-medium text-white"><?php echo htmlspecialchars($emp['employee_name']); ?></div>
                                            <div class="text-xs text-zinc-500"><?php echo htmlspecialchars($emp['employee_code']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 text-zinc-400 text-sm"><?php echo htmlspecialchars($emp['department_name'] ?? '-'); ?></td>
                                        <td class="px-6 py-4 text-zinc-400 text-sm"><?php echo htmlspecialchars($emp['position_name'] ?? '-'); ?></td>
                                        <td class="px-6 py-4 font-mono text-sm text-cyan-400"><?php echo $emp['ot_rate'] ?? '-'; ?></td>
                                        <td class="px-6 py-4 font-mono text-sm">
                                            <?php if ($emp['ot_pay'] > 0): ?>
                                                <span class="text-emerald-400 font-semibold"><?php echo $currency; ?> <?php echo number_format($emp['ot_pay'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-zinc-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo get_assignment_status_badge($emp_status); ?>"><?php echo $emp_status; ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-xs max-w-[250px]">
                                            <?php if (!empty($emp['validation_notes'])): ?>
                                                <span class="text-red-400" title="<?php echo htmlspecialchars($emp['validation_notes']); ?>"><?php echo htmlspecialchars($emp['validation_notes']); ?></span>
                                            <?php else: ?>
                                                <span class="text-zinc-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
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
