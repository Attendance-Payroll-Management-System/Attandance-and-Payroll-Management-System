<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';
require_once '../config/notifications.php';

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn);
$notifications = get_notifications($conn, null, 10);

$has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;

$employees = $conn->query("SELECT id, name, employee_code FROM employee WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_ot'])) {
    $employee_id = $_POST['employee_id'] ?? 0;
    $ot_date = $_POST['ot_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (!$employee_id || empty($ot_date) || empty($start_time) || empty($end_time)) {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $message = 'End time must be after start time.';
        $message_type = 'error';
    } else {
        $start_ts = strtotime($start_time);
        $end_ts = strtotime($end_time);
        $total_hours = round(($end_ts - $start_ts) / 3600, 2);

        $emp = $conn->prepare("SELECT name FROM employee WHERE id = ?");
        $emp->bind_param('i', $employee_id);
        $emp->execute();
        $emp_name = $emp->get_result()->fetch_assoc()['name'] ?? 'Employee';
        $emp->close();

        if ($has_source) {
            $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason, source, status) VALUES (?, ?, ?, ?, ?, ?, 'admin_assigned', 'Pending')");
        } else {
            $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
        }
        $stmt->bind_param('isssds', $employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason);
        if ($stmt->execute()) {
            create_notification($conn, $employee_id, 'ot_assigned', "OT assigned for $ot_date (" . number_format($total_hours, 1) . "h). Please accept or decline.", 'overtimerequest.php');
            $message = "Overtime assigned to $emp_name successfully.";
            $message_type = 'success';
        } else {
            $message = 'Error assigning overtime.';
            $message_type = 'error';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Assign Overtime</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Assign Overtime"; $page_subtitle = "Assign overtime work to employees."; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$has_source): ?>
            <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border bg-amber-500/20 border-amber-500/30 text-amber-400">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                <strong>Migration needed:</strong> Run <code class="bg-amber-500/20 px-1 rounded text-amber-400">config/migration_overtime_source.sql</code> to enable full assignment tracking. Assignments will work without it.
            </div>
            <?php endif; ?>

            <div class="max-w-2xl">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <h2 class="text-lg font-bold text-white mb-6"><i class="fa-solid fa-clock text-blue-400 mr-2"></i>New Overtime Assignment</h2>
                    <form method="POST" class="space-y-5 text-zinc-300">
                    <?php echo csrf_field(); ?>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Employee</label>
                            <select name="employee_id" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name'] . ' (' . $emp['employee_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">OT Date</label>
                            <input type="date" name="ot_date" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Start Time</label>
                                <input type="time" name="start_time" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">End Time</label>
                                <input type="time" name="end_time" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Reason / Instructions</label>
                            <textarea name="reason" rows="3" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30 resize-none" placeholder="Describe the overtime task..."></textarea>
                        </div>
                        <button type="submit" name="assign_ot" class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-clock"></i> Assign Overtime
                        </button>
                    </form>
                </div>
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
