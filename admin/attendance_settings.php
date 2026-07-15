<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$message = '';
$message_type = '';

// Ensure settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS attendance_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings = [
        'work_start_time' => $_POST['work_start_time'] ?? '09:00:00',
        'work_end_time' => $_POST['work_end_time'] ?? '17:00:00',
        'check_in_start_time' => $_POST['check_in_start_time'] ?? '08:30:00',
        'required_working_hours' => $_POST['required_working_hours'] ?? '8',
        'half_day_min_hours' => $_POST['half_day_min_hours'] ?? '4',
        'full_day_min_hours' => $_POST['full_day_min_hours'] ?? '8',
        'grace_period_minutes' => $_POST['grace_period_minutes'] ?? '0',
        'auto_absent_after_hours' => $_POST['auto_absent_after_hours'] ?? '4',
        'enable_auto_process' => isset($_POST['enable_auto_process']) ? '1' : '0',
        'timezone' => $_POST['timezone'] ?? 'Asia/Yangon',
    ];

    $errors = [];
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO attendance_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('sss', $key, $value, $value);
        if (!$stmt->execute()) {
            $errors[] = "Failed to save $key";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $message = 'Attendance settings saved successfully.';
        $message_type = 'success';
        log_activity($conn, $_SESSION['admin_id'], 'update_attendance_settings', 'Attendance settings updated');
    } else {
        $message = implode(', ', $errors);
        $message_type = 'error';
    }
}

// Get current settings
$settings_map = [];
$result = $conn->query("SELECT setting_key, setting_value FROM attendance_settings");
while ($row = $result->fetch_assoc()) {
    $settings_map[$row['setting_key']] = $row['setting_value'];
}

$work_start = $settings_map['work_start_time'] ?? '09:00:00';
$work_end = $settings_map['work_end_time'] ?? '17:00:00';
$check_in_start = $settings_map['check_in_start_time'] ?? '08:30:00';
$required_hours = $settings_map['required_working_hours'] ?? '8';
$half_day_min = $settings_map['half_day_min_hours'] ?? '4';
$full_day_min = $settings_map['full_day_min_hours'] ?? '8';
$grace_period = $settings_map['grace_period_minutes'] ?? '0';
$auto_absent = $settings_map['auto_absent_after_hours'] ?? '4';
$enable_auto = $settings_map['enable_auto_process'] ?? '1';
$timezone = $settings_map['timezone'] ?? 'Asia/Yangon';

// Check if correction table exists
$correction_table_exists = $conn->query("SHOW TABLES LIKE 'attendance_corrections'")->num_rows > 0;
$pending_corrections = 0;
if ($correction_table_exists) {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM attendance_corrections WHERE status = 'pending'");
    $pending_corrections = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance Settings</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ activeTab: 'general' }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php include "../includes/topbar.php"; ?>
        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter">

            <?php if ($message): ?>
            <div class="flex items-center gap-3 p-4 rounded-xl border text-sm <?php echo $message_type === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-xl shadow-lg shadow-emerald-500/25">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-extrabold text-white tracking-tight">Attendance Settings</h1>
                        <p class="text-sm text-zinc-400">Configure working hours, rules, and automation</p>
                    </div>
                </div>
                <?php if ($correction_table_exists && $pending_corrections > 0): ?>
                <a href="attendance_corrections.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-amber-500/10 border border-amber-500/20 text-amber-400 rounded-xl text-sm font-semibold hover:bg-amber-500/20 transition-all">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <?php echo $pending_corrections; ?> Pending Correction<?php echo $pending_corrections > 1 ? 's' : ''; ?>
                </a>
                <?php endif; ?>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 p-1 bg-white/[0.04] rounded-xl w-fit">
                <button @click="activeTab = 'general'" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all" :class="activeTab === 'general' ? 'bg-emerald-500/20 text-emerald-400 shadow-sm' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]'">
                    <i class="fa-solid fa-clock mr-2"></i>Working Hours
                </button>
                <button @click="activeTab = 'rules'" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all" :class="activeTab === 'rules' ? 'bg-emerald-500/20 text-emerald-400 shadow-sm' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]'">
                    <i class="fa-solid fa-scale-balanced mr-2"></i>Rules
                </button>
                <button @click="activeTab = 'automation'" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all" :class="activeTab === 'automation' ? 'bg-emerald-500/20 text-emerald-400 shadow-sm' : 'text-zinc-400 hover:text-white hover:bg-white/[0.04]'">
                    <i class="fa-solid fa-robot mr-2"></i>Automation
                </button>
            </div>

            <form method="POST">
                <div x-show="activeTab === 'general'" x-transition:enter="transition-all duration-200">
                    <div class="glass-strong rounded-2xl p-6 space-y-6">
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-clock text-emerald-400"></i> Working Hours Configuration
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Check-In Start Time</label>
                                <input type="time" name="check_in_start_time" value="<?php echo $check_in_start; ?>"
                                    class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                <p class="text-xs text-zinc-500 mt-1">Earliest time employees can check in</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Work Start Time</label>
                                <input type="time" name="work_start_time" value="<?php echo $work_start; ?>"
                                    class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                <p class="text-xs text-zinc-500 mt-1">Official start of work day (late threshold)</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Work End Time</label>
                                <input type="time" name="work_end_time" value="<?php echo $work_end; ?>"
                                    class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                <p class="text-xs text-zinc-500 mt-1">Official end of work day</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Required Working Hours/Day</label>
                                <input type="number" step="0.5" name="required_working_hours" value="<?php echo $required_hours; ?>"
                                    class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                <p class="text-xs text-zinc-500 mt-1">Standard hours per working day</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Timezone</label>
                                <select name="timezone"
                                    class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                    <option value="Asia/Yangon" <?php echo $timezone === 'Asia/Yangon' ? 'selected' : ''; ?>>Asia/Yangon (MMT, UTC+6:30)</option>
                                    <option value="Asia/Bangkok" <?php echo $timezone === 'Asia/Bangkok' ? 'selected' : ''; ?>>Asia/Bangkok (ICT, UTC+7:00)</option>
                                    <option value="Asia/Singapore" <?php echo $timezone === 'Asia/Singapore' ? 'selected' : ''; ?>>Asia/Singapore (SGT, UTC+8:00)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="activeTab === 'rules'" x-cloak x-transition:enter="transition-all duration-200">
                    <div class="glass-strong rounded-2xl p-6 space-y-6">
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-scale-balanced text-emerald-400"></i> Attendance Rules
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Half-Day Minimum Hours</label>
                                <input type="number" step="0.5" name="half_day_min_hours" value="<?php echo $half_day_min; ?>"
                                    class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                <p class="text-xs text-zinc-500 mt-1">Minimum hours worked to count as half day</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Full-Day Minimum Hours</label>
                                <input type="number" step="0.5" name="full_day_min_hours" value="<?php echo $full_day_min; ?>"
                                    class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                <p class="text-xs text-zinc-500 mt-1">Minimum hours worked to count as full day</p>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Grace Period (minutes)</label>
                                <input type="number" name="grace_period_minutes" value="<?php echo $grace_period; ?>"
                                    class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                <p class="text-xs text-zinc-500 mt-1">Minutes after start time before considered late</p>
                            </div>
                        </div>

                        <!-- Status Rules Info -->
                        <div class="mt-6 border-t border-white/[0.06] pt-6">
                            <h4 class="font-semibold text-white mb-3">Status Determination Logic</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                                <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-3">
                                    <span class="font-bold text-emerald-400">Present</span>
                                    <p class="text-zinc-400 mt-1">≥ <?php echo $full_day_min; ?>h worked, on-time check-in</p>
                                </div>
                                <div class="bg-amber-500/10 border border-amber-500/20 rounded-xl p-3">
                                    <span class="font-bold text-amber-400">Late</span>
                                    <p class="text-zinc-400 mt-1">≥ <?php echo $full_day_min; ?>h worked, late check-in</p>
                                </div>
                                <div class="bg-teal-500/10 border border-teal-500/20 rounded-xl p-3">
                                    <span class="font-bold text-teal-400">Half Day</span>
                                    <p class="text-zinc-400 mt-1"><?php echo $half_day_min; ?>-<?php echo $full_day_min; ?>h worked</p>
                                </div>
                                <div class="bg-red-500/10 border border-red-500/20 rounded-xl p-3">
                                    <span class="font-bold text-red-400">Absent</span>
                                    <p class="text-zinc-400 mt-1">&lt; <?php echo $half_day_min; ?>h or no check-in</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="activeTab === 'automation'" x-cloak x-transition:enter="transition-all duration-200">
                    <div class="glass-strong rounded-2xl p-6 space-y-6">
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-robot text-emerald-400"></i> Automation Settings
                        </h3>

                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-4 bg-white/[0.03] rounded-xl">
                                <div>
                                    <p class="text-sm font-semibold text-white">Enable Automatic Daily Processing</p>
                                    <p class="text-xs text-zinc-400 mt-0.5">Auto-mark weekends, holidays, AWOL, and leave daily</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="enable_auto_process" value="1" <?php echo $enable_auto === '1' ? 'checked' : ''; ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-white/[0.08] rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                                </label>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1.5">Auto-Absent After (hours from start)</label>
                                <input type="number" step="0.5" name="auto_absent_after_hours" value="<?php echo $auto_absent; ?>"
                                    class="w-full max-w-xs bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                <p class="text-xs text-zinc-500 mt-1">Auto-mark as absent if no check-in within X hours of work start</p>
                            </div>
                        </div>

                        <div class="border-t border-white/[0.06] pt-6">
                            <h4 class="font-semibold text-white mb-3">Quick Actions</h4>
                            <div class="flex flex-wrap gap-3">
                                <a href="process_daily_attendance.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 rounded-xl text-sm font-semibold hover:bg-indigo-500/20 transition-all">
                                    <i class="fa-solid fa-play"></i> Run Daily Processing Now
                                </a>
                                <a href="attendance_summary.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-sky-500/10 border border-sky-500/20 text-sky-400 rounded-xl text-sm font-semibold hover:bg-sky-500/20 transition-all">
                                    <i class="fa-solid fa-chart-bar"></i> View Attendance Summary
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="submit" name="save_settings"
                        class="bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 text-white font-semibold px-8 py-3 rounded-xl shadow-lg shadow-emerald-500/25 transition-all duration-200 hover:scale-105">
                        <i class="fa-solid fa-floppy-disk mr-2"></i> Save Settings
                    </button>
                </div>
            </form>

            <!-- Current Configuration Summary -->
            <div class="glass-strong rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-circle-info text-blue-400"></i> Current Configuration Summary
                </h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div class="bg-white/[0.03] rounded-xl p-3">
                        <p class="text-xs text-zinc-500">Timezone</p>
                        <p class="text-sm font-semibold text-white mt-0.5"><?php echo $timezone; ?></p>
                    </div>
                    <div class="bg-white/[0.03] rounded-xl p-3">
                        <p class="text-xs text-zinc-500">Check-In Window</p>
                        <p class="text-sm font-semibold text-white mt-0.5"><?php echo date('h:i A', strtotime($check_in_start)); ?> - <?php echo date('h:i A', strtotime($work_end)); ?></p>
                    </div>
                    <div class="bg-white/[0.03] rounded-xl p-3">
                        <p class="text-xs text-zinc-500">Late After</p>
                        <p class="text-sm font-semibold text-amber-400 mt-0.5"><?php echo date('h:i A', strtotime($work_start)); ?></p>
                    </div>
                    <div class="bg-white/[0.03] rounded-xl p-3">
                        <p class="text-xs text-zinc-500">Required Hours</p>
                        <p class="text-sm font-semibold text-white mt-0.5"><?php echo $required_hours; ?>h/day</p>
                    </div>
                    <div class="bg-white/[0.03] rounded-xl p-3">
                        <p class="text-xs text-zinc-500">Half-Day Threshold</p>
                        <p class="text-sm font-semibold text-teal-400 mt-0.5">≥ <?php echo $half_day_min; ?>h</p>
                    </div>
                    <div class="bg-white/[0.03] rounded-xl p-3">
                        <p class="text-xs text-zinc-500">Full-Day Threshold</p>
                        <p class="text-sm font-semibold text-emerald-400 mt-0.5">≥ <?php echo $full_day_min; ?>h</p>
                    </div>
                    <div class="bg-white/[0.03] rounded-xl p-3">
                        <p class="text-xs text-zinc-500">Grace Period</p>
                        <p class="text-sm font-semibold text-white mt-0.5"><?php echo $grace_period; ?> min</p>
                    </div>
                    <div class="bg-white/[0.03] rounded-xl p-3">
                        <p class="text-xs text-zinc-500">Auto Processing</p>
                        <p class="text-sm font-semibold <?php echo $enable_auto === '1' ? 'text-emerald-400' : 'text-zinc-500'; ?> mt-0.5"><?php echo $enable_auto === '1' ? 'Enabled' : 'Disabled'; ?></p>
                    </div>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
