<?php
/**
 * Daily Attendance Processing Script
 * 
 * Auto-marks attendance for all active employees:
 * - Saturdays/Sundays -> weekend
 * - Public holidays -> public_holiday
 * - Working days without check-in and no approved leave -> AWOL
 * - Auto check-out for employees who forgot (no approved OT, after 17:30 MMT)
 * 
 * Also reconciles missing Pension Fund deductions for AWOL records.
 * 
 * Can be triggered:
 * 1. Manually by admin via browser
 * 2. As a cron job (CLI)
 * 
 * Usage (CLI): php admin/process_daily_attendance.php [date] [--reconcile] [--auto-checkout]
 * Usage (Web): Navigate to this page in admin panel
 */

session_start();

// CLI mode detection
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    require_once '../config/auth.php';
    require_admin_login();
}

require_once '../config/db.php';
require_once '../config/helpers.php';
require_once '../config/notifications.php';

set_mmt_timezone();

// Get date to process (default: today)
$process_date = '';

if ($is_cli) {
    $process_date = $argv[1] ?? mmt_date('Y-m-d');
} else {
    $process_date = $_GET['date'] ?? $_POST['date'] ?? mmt_date('Y-m-d');
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $process_date)) {
    $error_msg = "Invalid date format. Use YYYY-MM-DD.";
    if ($is_cli) { echo "$error_msg\n"; exit(1); }
    else { $_SESSION['message'] = $error_msg; $_SESSION['message_type'] = 'error'; header('Location: dashboard.php'); exit; }
}

$reconcile_only = false;
if ($is_cli && in_array('--reconcile', $argv ?? [])) {
    $reconcile_only = true;
}
if (!$is_cli && isset($_POST['reconcile'])) {
    $reconcile_only = true;
}

$auto_checkout_only = false;
if ($is_cli && in_array('--auto-checkout', $argv ?? [])) {
    $auto_checkout_only = true;
}
if (!$is_cli && isset($_POST['auto_checkout'])) {
    $auto_checkout_only = true;
}

$message = '';
$message_type = '';

// Handle Reconcile Deductions
if ($reconcile_only) {
    $recon_result = reconcile_awol_deductions($conn, $process_date);
    $message = "Deduction Reconcile for $process_date: Checked {$recon_result['checked']} AWOL records, Created {$recon_result['created']} new deductions, Skipped {$recon_result['skipped']} existing.";
    $message_type = !empty($recon_result['errors']) ? 'error' : 'success';

    if (!empty($recon_result['errors'])) {
        $message .= " Errors: " . implode('; ', $recon_result['errors']);
    }

    if ($is_cli) {
        echo $message . "\n";
        exit(0);
    }
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
}

// Handle Auto Check-Out
if ($auto_checkout_only) {
    $auto_result = process_auto_checkout($conn, $process_date);
    $message = "Auto Check-Out for $process_date: Processed {$auto_result['processed']} employees, Auto-checked-out {$auto_result['auto_checkout']}, Skipped (OT) {$auto_result['skipped_ot']}.";
    $message_type = !empty($auto_result['errors']) ? 'error' : 'success';

    if (!empty($auto_result['errors'])) {
        $message .= " Errors: " . implode('; ', $auto_result['errors']);
    }

    if ($is_cli) {
        echo $message . "\n";
        exit(0);
    }
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
}

// Handle Process Attendance
if (!$reconcile_only && !$auto_checkout_only && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_attendance'])) {
    $result = auto_mark_daily_attendance($conn, $process_date);

    // Try to log the processing run (table may not exist)
    $log_check = $conn->query("SHOW TABLES LIKE 'attendance_processing_log'");
    if ($log_check && $log_check->num_rows > 0) {
        $log = $conn->prepare("INSERT INTO attendance_processing_log (process_date, processed_by, employees_processed, awol_marked, weekend_marked, holiday_marked) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE processed_by = VALUES(processed_by), employees_processed = VALUES(employees_processed), awol_marked = VALUES(awol_marked), weekend_marked = VALUES(weekend_marked), holiday_marked = VALUES(holiday_marked)");
        $who = 'admin_' . ($_SESSION['admin_id'] ?? 0);
        $log->bind_param('ssiiii', $process_date, $who, $result['processed'], $result['awol'], $result['weekend'], $result['holiday']);
        $log->execute();
        $log->close();
    }

    // Also reconcile deductions for any AWOL and Half-Day records
    $recon = reconcile_awol_deductions($conn, $process_date);

    // Auto check-out employees who forgot
    $auto_result = process_auto_checkout($conn, $process_date);

    $message = "Processed {$result['processed']} employees: {$result['awol']} AWOL, {$result['weekend']} Weekend, {$result['holiday']} Holiday. Deductions: {$recon['created']} created, {$recon['skipped']} existing. Auto check-outs: {$auto_result['auto_checkout']}.";
    $message_type = 'success';

    if ($is_cli) {
        echo $message . "\n";
        exit(0);
    }
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
}

// CLI mode: run processing + auto-checkout + reconcile
if ($is_cli && !$reconcile_only && !$auto_checkout_only) {
    $result = auto_mark_daily_attendance($conn, $process_date);

    $log_check = $conn->query("SHOW TABLES LIKE 'attendance_processing_log'");
    if ($log_check && $log_check->num_rows > 0) {
        $log = $conn->prepare("INSERT INTO attendance_processing_log (process_date, processed_by, employees_processed, awol_marked, weekend_marked, holiday_marked) VALUES (?, 'cron', ?, ?, ?, ?)");
        $log->bind_param('siiii', $process_date, $result['processed'], $result['awol'], $result['weekend'], $result['holiday']);
        $log->execute();
        $log->close();
    }

    $recon = reconcile_awol_deductions($conn, $process_date);

    // Auto check-out employees who forgot
    $auto_result = process_auto_checkout($conn, $process_date);

    echo "Processed {$result['processed']} employees on $process_date.\n";
    echo "  AWOL: {$result['awol']}\n";
    echo "  Weekend: {$result['weekend']}\n";
    echo "  Public Holiday: {$result['holiday']}\n";
    echo "  Deductions created: {$recon['created']}\n";
    echo "  Deductions existing (skipped): {$recon['skipped']}\n";
    echo "  Auto check-outs: {$auto_result['auto_checkout']}\n";
    echo "  Skipped (OT active): {$auto_result['skipped_ot']}\n";
    exit(0);
}

// Get processing history for display
$processing_log = [];
$log_table_check = $conn->query("SHOW TABLES LIKE 'attendance_processing_log'");
if ($log_table_check && $log_table_check->num_rows > 0) {
    $logs_result = $conn->query("SELECT * FROM attendance_processing_log ORDER BY process_date DESC LIMIT 20");
    if ($logs_result) {
        $processing_log = $logs_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get AWOL and Half-Day deduction summary for the selected date
$awol_summary = [];
$awol_stmt = $conn->prepare(
    "SELECT a.id, a.employee_id, e.name, e.employee_code, a.status,
            (SELECT d.id FROM deductions d WHERE d.employee_id = a.employee_id AND d.deduction_date = a.attendance_date AND d.remarks IN (?, ?) LIMIT 1) as deduction_id
     FROM attendance a
     JOIN employee e ON a.employee_id = e.id
     WHERE a.attendance_date = ? AND a.status IN ('awol', 'absent', 'full_absent', 'half_day')
     ORDER BY e.name"
);
$awol_remarks = UNPAID_ABSENCE_DEDUCTION_REMARKS;
$half_day_remarks = HALF_DAY_DEDUCTION_REMARKS;
$awol_stmt->bind_param('sss', $awol_remarks, $half_day_remarks, $process_date);
$awol_stmt->execute();
$awol_summary = $awol_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$awol_stmt->close();

// Web mode - show results page
if (!$is_cli):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Process Daily Attendance</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Process Daily Attendance"; $page_subtitle = "Auto-mark weekend, holiday, AWOL attendance and ensure Pension Fund deductions are created."; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <div class="max-w-3xl mx-auto">

                <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Process Attendance -->
                    <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="text-lg font-bold text-white mb-4"><i class="fa-solid fa-robot text-blue-400 mr-2"></i>Process Attendance</h2>

                        <form method="POST" class="space-y-4">
                        <?php echo csrf_field(); ?>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Process Date</label>
                                <input type="date" name="date" value="<?php echo htmlspecialchars($process_date); ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            </div>

                            <div class="bg-amber-500/10 border border-amber-500/20 rounded-xl p-4 text-sm text-amber-400">
                                <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                                <strong>What this does:</strong>
                                <ul class="mt-2 space-y-1 text-zinc-400 ml-5 list-disc">
                                    <li>Marks <strong>Weekend</strong> on Saturdays/Sundays</li>
                                    <li>Marks <strong>Public Holiday</strong> on holidays</li>
                                    <li>Marks <strong>AWOL</strong> on working days without check-in</li>
                                    <li>Auto check-out employees at <strong>5:30 PM</strong> (no OT)</li>
                                    <li>Creates <strong>Pension Fund deductions (2%)</strong> for AWOL records</li>
                                </ul>
                            </div>

                            <button type="submit" name="process_attendance" value="1" class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-play"></i> Process Attendance
                            </button>
                        </form>
                    </div>

                    <!-- Reconcile Deductions -->
                    <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="text-lg font-bold text-white mb-4"><i class="fa-solid fa-wrench text-amber-400 mr-2"></i>Reconcile Deductions</h2>

                        <form method="POST" class="space-y-4">
                        <?php echo csrf_field(); ?>
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($process_date); ?>">

                            <div class="bg-rose-500/10 border border-rose-500/20 rounded-xl p-4 text-sm text-rose-400">
                                <i class="fa-solid fa-circle-info mr-2"></i>
                                <strong>What this does:</strong>
                                <ul class="mt-2 space-y-1 text-zinc-400 ml-5 list-disc">
                                    <li>Scans all <strong>AWOL</strong> attendance records for the date</li>
                                    <li>Creates missing <strong>Pension Fund deductions (2%)</strong></li>
                                    <li>Safe to run multiple times (skips existing)</li>
                                </ul>
                            </div>

                            <button type="submit" name="reconcile" value="1" class="w-full rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-wrench"></i> Reconcile Deductions
                            </button>
                        </form>
                    </div>

                    <!-- Auto Check-Out -->
                    <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="text-lg font-bold text-white mb-4"><i class="fa-solid fa-clock text-violet-400 mr-2"></i>Auto Check-Out</h2>

                        <form method="POST" class="space-y-4">
                        <?php echo csrf_field(); ?>
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($process_date); ?>">

                            <div class="bg-violet-500/10 border border-violet-500/20 rounded-xl p-4 text-sm text-violet-400">
                                <i class="fa-solid fa-circle-info mr-2"></i>
                                <strong>What this does:</strong>
                                <ul class="mt-2 space-y-1 text-zinc-400 ml-5 list-disc">
                                    <li>Finds employees who <strong>checked in but forgot to check out</strong></li>
                                    <li>Auto checks them out at <strong>5:30 PM MMT</strong> (no approved OT)</li>
                                    <li>Skips employees with <strong>approved overtime</strong> requests</li>
                                    <li>Flags records with <strong>is_auto_checkout = 1</strong></li>
                                </ul>
                            </div>

                            <button type="submit" name="auto_checkout" value="1" class="w-full rounded-xl bg-gradient-to-r from-violet-500 to-purple-600 hover:from-violet-600 hover:to-purple-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-clock-rotate-left"></i> Run Auto Check-Out
                            </button>
                        </form>
                    </div>
                </div>

                <!-- AWOL Summary for Selected Date -->
                <?php if (!empty($awol_summary)): ?>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6 mt-6">
                    <h2 class="text-lg font-bold text-white mb-4"><i class="fa-solid fa-users-slash text-red-400 mr-2"></i>Attendance Violations — <?php echo htmlspecialchars($process_date); ?></h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="py-3 font-semibold">Employee</th>
                                    <th class="py-3 font-semibold">Code</th>
                                    <th class="py-3 font-semibold">Status</th>
                                    <th class="py-3 font-semibold text-center">Deduction</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                                <?php foreach ($awol_summary as $aw): ?>
                                <tr>
                                    <td class="py-3 font-medium text-white"><?php echo htmlspecialchars($aw['name']); ?></td>
                                    <td class="py-3 text-zinc-400 font-mono text-xs"><?php echo htmlspecialchars($aw['employee_code']); ?></td>
                                    <td class="py-3"><span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-red-700/20 text-red-500"><?php echo strtoupper($aw['status']); ?></span></td>
                                    <td class="py-3 text-center">
                                        <?php if ($aw['deduction_id']): ?>
                                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-500/15 text-emerald-400 border border-emerald-500/20"><i class="fa-solid fa-check text-[10px]"></i> Applied</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-500/15 text-amber-400 border border-amber-500/20"><i class="fa-solid fa-exclamation text-[10px]"></i> Missing</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Processing Log -->
                <?php if (!empty($processing_log)): ?>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6 mt-6">
                    <h2 class="text-lg font-bold text-white mb-4"><i class="fa-solid fa-clock-rotate-left text-blue-400 mr-2"></i>Processing History</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="py-3 font-semibold">Date</th>
                                    <th class="py-3 font-semibold">Processed</th>
                                    <th class="py-3 font-semibold">AWOL</th>
                                    <th class="py-3 font-semibold">Weekend</th>
                                    <th class="py-3 font-semibold">Holiday</th>
                                    <th class="py-3 font-semibold">By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                                <?php foreach ($processing_log as $log): ?>
                                <tr>
                                    <td class="py-3 font-medium text-white"><?php echo htmlspecialchars($log['process_date']); ?></td>
                                    <td class="py-3"><?php echo $log['employees_processed']; ?></td>
                                    <td class="py-3 text-red-400"><?php echo $log['awol_marked']; ?></td>
                                    <td class="py-3 text-purple-400"><?php echo $log['weekend_marked']; ?></td>
                                    <td class="py-3 text-pink-400"><?php echo $log['holiday_marked']; ?></td>
                                    <td class="py-3 text-zinc-500 text-xs"><?php echo htmlspecialchars($log['processed_by']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cron Setup Instructions -->
                <div class="glass-strong rounded-2xl p-6 mt-6">
                    <h3 class="font-bold text-white text-sm mb-2"><i class="fa-solid fa-terminal text-emerald-400 mr-2"></i>Automated Cron Setup (Linux)</h3>
                    <p class="text-xs text-zinc-400 mb-2">Add this to your crontab to run daily at 11:45 PM (processes + auto-checkout + reconcile):</p>
                    <pre class="bg-black/40 text-emerald-300 text-xs p-3 rounded-lg overflow-x-auto">45 23 * * * /usr/bin/php /path/to/admin/process_daily_attendance.php</pre>
                    <p class="text-xs text-zinc-400 mt-2">For auto-checkout only at 5:35 PM MMT:</p>
                    <pre class="bg-black/40 text-emerald-300 text-xs p-3 rounded-lg overflow-x-auto">35 17 * * * /usr/bin/php /path/to/admin/process_daily_attendance.php $(date +\%F) --auto-checkout</pre>
                    <p class="text-xs text-zinc-500 mt-2">This will auto-mark attendance, auto-checkout employees, and create Pension Fund deductions for AWOL.</p>
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
<?php endif; ?>
