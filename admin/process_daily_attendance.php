<?php
/**
 * Daily Attendance Processing Script
 * 
 * Auto-marks attendance for all active employees:
 * - Saturdays/Sundays → weekend
 * - Public holidays → public_holiday
 * - Working days without check-in and no approved leave → AWOL
 * 
 * Can be triggered:
 * 1. Manually by admin via browser
 * 2. As a cron job (CLI)
 * 
 * Usage (CLI): php admin/process_daily_attendance.php [date]
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

// Get date to process (default: yesterday for cron, today for web)
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

// Check if already processed
$log_check = $conn->prepare("SELECT id FROM attendance_processing_log WHERE process_date = ?");
$log_check->bind_param('s', $process_date);
$log_check->execute();
$log_check->store_result();
$already_processed = $log_check->num_rows > 0;
$log_check->close();

if (!$is_cli && !$already_processed) {
    // Run processing
    $result = auto_mark_daily_attendance($conn, $process_date);

    // Log the processing run
    $log = $conn->prepare("INSERT INTO attendance_processing_log (process_date, processed_by, employees_processed, awol_marked, weekend_marked, holiday_marked) VALUES (?, ?, ?, ?, ?, ?)");
    $who = $is_cli ? 'cron' : ('admin_' . ($_SESSION['admin_id'] ?? 0));
    $log->bind_param('ssiiii', $process_date, $who, $result['processed'], $result['awol'], $result['weekend'], $result['holiday']);
    $log->execute();
    $log->close();

    $summary = "Processed {$result['processed']} employees: {$result['awol']} AWOL, {$result['weekend']} Weekend, {$result['holiday']} Public Holiday.";
    $_SESSION['message'] = $summary;
    $_SESSION['message_type'] = 'success';
}

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
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Process Daily Attendance"; $page_subtitle = "Automatically mark weekend, holiday, and AWOL attendance for all active employees."; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <div class="max-w-2xl mx-auto">

                <?php
                $msg = get_message();
                if ($msg['message']):
                ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $msg['message_type'] == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $msg['message_type'] == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($msg['message']); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <h2 class="text-lg font-bold text-white mb-6"><i class="fa-solid fa-robot text-blue-400 mr-2"></i>Attendance Processor</h2>

                    <form method="POST" class="space-y-4">
                    <?php echo csrf_field(); ?>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Process Date</label>
                            <input type="date" name="date" value="<?php echo $process_date; ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            <p class="text-xs text-zinc-500 mt-1">Select the date to process attendance for.</p>
                        </div>

                        <div class="bg-amber-500/10 border border-amber-500/20 rounded-xl p-4 text-sm text-amber-400">
                            <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                            <strong>What this does:</strong>
                            <ul class="mt-2 space-y-1 text-zinc-400 ml-5 list-disc">
                                <li>Marks all active employees as <strong>Weekend</strong> on Saturdays/Sundays</li>
                                <li>Marks all active employees as <strong>Public Holiday</strong> on holidays</li>
                                <li>Marks employees without check-in as <strong>AWOL</strong> on working days</li>
                                <li>Skips employees who already have attendance records or approved leave</li>
                            </ul>
                        </div>

                        <?php if ($already_processed): ?>
                        <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 text-sm text-blue-400">
                            <i class="fa-solid fa-circle-info mr-2"></i>
                            Attendance has already been processed for <?php echo $process_date; ?>. Processing again will only add records for employees not yet processed.
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-play"></i> Process Attendance for <?php echo $process_date; ?>
                        </button>
                    </form>
                </div>

                <!-- Processing Log -->
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
                                <?php
                                $logs = $conn->query("SELECT * FROM attendance_processing_log ORDER BY process_date DESC LIMIT 20");
                                if ($logs && $logs->num_rows > 0):
                                    while ($log = $logs->fetch_assoc()):
                                ?>
                                <tr>
                                    <td class="py-3 font-medium text-white"><?php echo $log['process_date']; ?></td>
                                    <td class="py-3"><?php echo $log['employees_processed']; ?></td>
                                    <td class="py-3 text-red-400"><?php echo $log['awol_marked']; ?></td>
                                    <td class="py-3 text-purple-400"><?php echo $log['weekend_marked']; ?></td>
                                    <td class="py-3 text-pink-400"><?php echo $log['holiday_marked']; ?></td>
                                    <td class="py-3 text-zinc-500 text-xs"><?php echo htmlspecialchars($log['processed_by']); ?></td>
                                </tr>
                                <?php
                                    endwhile;
                                else:
                                ?>
                                <tr>
                                    <td colspan="6" class="py-6 text-center text-zinc-500">No processing history yet.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Cron Setup Instructions -->
                <div class="glass-strong rounded-2xl p-6 mt-6">
                    <h3 class="font-bold text-white text-sm mb-2"><i class="fa-solid fa-terminal text-emerald-400 mr-2"></i>Automated Cron Setup (Linux)</h3>
                    <p class="text-xs text-zinc-400 mb-2">Add this to your crontab to run daily at 11:45 PM:</p>
                    <pre class="bg-black/40 text-emerald-300 text-xs p-3 rounded-lg overflow-x-auto">45 23 * * * /usr/bin/php /path/to/admin/process_daily_attendance.php</pre>
                    <p class="text-xs text-zinc-500 mt-2">This will auto-mark attendance for the current day.</p>
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
<?php
endif;
// CLI mode output
if ($is_cli) {
    if ($already_processed) {
        echo "Attendance already processed for $process_date.\n";
    } else {
        $result = auto_mark_daily_attendance($conn, $process_date);
        $log = $conn->prepare("INSERT INTO attendance_processing_log (process_date, processed_by, employees_processed, awol_marked, weekend_marked, holiday_marked) VALUES (?, 'cron', ?, ?, ?, ?)");
        $log->bind_param('siiii', $process_date, $result['processed'], $result['awol'], $result['weekend'], $result['holiday']);
        $log->execute();
        $log->close();
        echo "Processed {$result['processed']} employees on $process_date.\n";
        echo "  AWOL: {$result['awol']}\n";
        echo "  Weekend: {$result['weekend']}\n";
        echo "  Public Holiday: {$result['holiday']}\n";
    }
}
