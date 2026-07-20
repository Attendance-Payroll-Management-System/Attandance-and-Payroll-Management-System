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

$admin_id = $_SESSION['admin_id'] ?? 0;

// Check if overtime_assignments table exists
$has_table = $conn->query("SHOW TABLES LIKE 'overtime_assignments'")->num_rows > 0;

if (!$has_table) {
    $message = 'Overtime Assignment module not installed. Run migration_overtime_assignment_module.sql first.';
    $message_type = 'error';
}

// Handle cancel action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_assignment'])) {
    if (!validate_csrf_token()) {
        $message = 'Invalid request.';
        $message_type = 'error';
    } else {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        if ($assignment_id > 0 && $has_table) {
            $result = cancel_overtime_assignment($conn, $assignment_id);
            if ($result) {
                $message = 'Assignment cancelled successfully.';
                $message_type = 'success';
                log_activity($conn, $admin_id, 'overtime_assignment_cancelled', "Cancelled assignment #$assignment_id");
            } else {
                $message = 'Error cancelling assignment.';
                $message_type = 'error';
            }
        }
    }
}

// Filters
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$dept_filter = $_GET['department'] ?? '';

$filters = [
    'from_date' => $from_date,
    'to_date' => $to_date,
];
if ($status_filter) $filters['status'] = $status_filter;
if ($type_filter) $filters['assignment_type'] = $type_filter;
if ($dept_filter) $filters['department_id'] = (int)$dept_filter;

$assignments = $has_table ? get_overtime_assignments($conn, $filters) : [];

// Get departments for filter
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

// Stats
$total_assignments = count($assignments);
$assigned_count = 0;
$cancelled_count = 0;
$total_employees = 0;
foreach ($assignments as $a) {
    if ($a['status'] === 'Assigned') $assigned_count++;
    if ($a['status'] === 'Cancelled') $cancelled_count++;
    $total_employees += (int)($a['total_employees'] ?? 0);
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · OT Assignments</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Overtime Assignments";
            $page_subtitle = "Manage and track overtime assignments.";
            ob_start();
        ?>
        <div class="flex items-center gap-2">
            <a href="assign_overtime.php" class="rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-2 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> New Assignment
            </a>
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

            <?php if (!$has_table): ?>
            <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border bg-amber-500/20 border-amber-500/30 text-amber-400">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                <strong>Migration needed:</strong> Run <code class="bg-amber-500/20 px-1 rounded text-amber-400">config/migration_overtime_assignment_module.sql</code> to enable the Overtime Assignment module.
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Assignments</span>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center"><i class="fa-solid fa-clipboard-list text-blue-400 text-sm"></i></div>
                    </div>
                    <p class="text-2xl font-bold text-white"><?php echo $total_assignments; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Active</span>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500/20 to-green-500/10 flex items-center justify-center"><i class="fa-solid fa-circle-check text-emerald-400 text-sm"></i></div>
                    </div>
                    <p class="text-2xl font-bold text-emerald-400"><?php echo $assigned_count; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Cancelled</span>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-zinc-500/20 to-zinc-600/10 flex items-center justify-center"><i class="fa-solid fa-ban text-zinc-400 text-sm"></i></div>
                    </div>
                    <p class="text-2xl font-bold text-zinc-400"><?php echo $cancelled_count; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Employees</span>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500/20 to-purple-600/10 flex items-center justify-center"><i class="fa-solid fa-users text-purple-400 text-sm"></i></div>
                    </div>
                    <p class="text-2xl font-bold text-purple-400"><?php echo $total_employees; ?></p>
                </div>
            </section>

            <!-- Filters -->
            <section class="mb-6">
                <form method="GET" class="glass-strong rounded-2xl p-4 flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="text-xs font-semibold text-zinc-400 block mb-1">From</label>
                        <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg px-3 py-2">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-zinc-400 block mb-1">To</label>
                        <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg px-3 py-2">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-zinc-400 block mb-1">Status</label>
                        <select name="status" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg px-3 py-2">
                            <option value="">All Status</option>
                            <option value="Assigned" <?php echo $status_filter === 'Assigned' ? 'selected' : ''; ?>>Assigned</option>
                            <option value="Accepted" <?php echo $status_filter === 'Accepted' ? 'selected' : ''; ?>>Accepted</option>
                            <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-zinc-400 block mb-1">Type</label>
                        <select name="type" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg px-3 py-2">
                            <option value="">All Types</option>
                            <option value="department" <?php echo $type_filter === 'department' ? 'selected' : ''; ?>>Department</option>
                            <option value="employee" <?php echo $type_filter === 'employee' ? 'selected' : ''; ?>>Employee</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-zinc-400 block mb-1">Department</label>
                        <select name="department" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg px-3 py-2">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo $dept_filter == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-2 shadow-sm transition">
                        <i class="fa-solid fa-magnifying-glass mr-1"></i> Filter
                    </button>
                </form>
            </section>

            <!-- Assignments Table -->
            <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-clipboard-list text-blue-400 mr-2"></i>Assignments</h2>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo $total_assignments; ?> records</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Code</th>
                                <th class="px-6 py-4">Type</th>
                                <th class="px-6 py-4">Target</th>
                                <th class="px-6 py-4">OT Date</th>
                                <th class="px-6 py-4">Time</th>
                                <th class="px-6 py-4">Hours</th>
                                <th class="px-6 py-4">OT Type</th>
                                <th class="px-6 py-4">Employees</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4">Created</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($assignments)): ?>
                            <tr>
                                <td colspan="11" class="px-6 py-12 text-center text-zinc-500">
                                    <p class="text-lg mb-2">No assignments found.</p>
                                    <a href="assign_overtime.php" class="text-blue-400 hover:underline text-sm">Create your first assignment</a>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($assignments as $a): ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-4">
                                        <a href="assignment_detail.php?id=<?php echo $a['id']; ?>" class="font-mono text-xs font-bold text-blue-400 hover:underline"><?php echo htmlspecialchars($a['assignment_code']); ?></a>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold <?php echo $a['assignment_type'] === 'department' ? 'text-cyan-400' : 'text-purple-400'; ?>">
                                            <i class="fa-solid <?php echo $a['assignment_type'] === 'department' ? 'fa-building' : 'fa-user'; ?>"></i>
                                            <?php echo ucfirst($a['assignment_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <?php if ($a['assignment_type'] === 'department'): ?>
                                            <span class="text-white font-medium"><?php echo htmlspecialchars($a['department_name'] ?? '-'); ?></span>
                                        <?php else: ?>
                                            <div>
                                                <span class="text-white font-medium"><?php echo htmlspecialchars($a['employee_name'] ?? '-'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($a['ot_date'])); ?></td>
                                    <td class="px-6 py-4 font-mono text-xs">
                                        <?php echo date('h:i A', strtotime($a['start_time'])); ?> - <?php echo date('h:i A', strtotime($a['end_time'])); ?>
                                    </td>
                                    <td class="px-6 py-4 font-semibold"><?php echo $a['total_hours']; ?>h</td>
                                    <td class="px-6 py-4">
                                        <?php echo get_overtime_type_badge($a['ot_type']); ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="font-semibold text-white"><?php echo $a['total_employees'] ?? 0; ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo get_assignment_status_badge($a['status']); ?>"><?php echo $a['status']; ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-xs text-zinc-500"><?php echo date('M d, h:i A', strtotime($a['created_at'])); ?></td>
                                    <td class="px-6 py-4 text-right whitespace-nowrap">
                                        <div class="flex items-center gap-2 justify-end">
                                            <a href="assignment_detail.php?id=<?php echo $a['id']; ?>" class="rounded-lg bg-white/[0.06] hover:bg-white/[0.1] text-zinc-400 hover:text-white px-3 py-1.5 text-xs transition">
                                                <i class="fa-solid fa-eye mr-1"></i>View
                                            </a>
                                            <?php if ($a['status'] === 'Assigned'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Cancel this assignment?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                                                <button type="submit" name="cancel_assignment" class="rounded-lg border border-red-500/30 hover:bg-red-500/10 text-red-400 px-3 py-1.5 text-xs transition">
                                                    <i class="fa-solid fa-ban mr-1"></i>Cancel
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
