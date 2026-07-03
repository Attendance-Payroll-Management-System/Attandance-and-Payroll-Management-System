<?php
session_start();
require_once "../config/db.php";
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

$message = '';
$message_type = '';

$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$settings = [];
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    foreach ($_POST as $key => $value) {
        if (array_key_exists($key, $settings)) {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->bind_param("ss", $value, $key);
            $stmt->execute();
            $stmt->close();
            $settings[$key] = $value;
        }
    }
    $message = "Settings saved successfully!";
    $message_type = "success";
}
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · System Settings</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "System Settings"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto page-content w-full">
            <div class="animate-fade-in-up mb-8">
                <h1 class="text-2xl font-bold text-body tracking-tight">System Settings</h1>
                <p class="text-sm text-body-secondary mt-1">Configure company information, payroll rules, and system preferences.</p>
            </div>

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?> animate-fade-in-up">
                    <div class="flex items-center gap-3"><i class="fa-solid fa-circle-check text-lg"></i><p class="font-medium"><?php echo htmlspecialchars($message); ?></p></div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-1">
                        <h3 class="font-bold text-white text-base mb-5 border-b border-white/[0.06] pb-4"><i class="fa-solid fa-building text-violet-400 mr-2"></i>Company Information</h3>
                        <div class="space-y-4">
                            <div><label class="text-sm font-medium text-zinc-300">Company Name</label><input type="text" name="company_name" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Address</label><input type="text" name="company_address" value="<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Phone</label><input type="text" name="company_phone" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Email</label><input type="email" name="company_email" value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Website</label><input type="text" name="company_website" value="<?php echo htmlspecialchars($settings['company_website'] ?? ''); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-2">
                        <h3 class="font-bold text-white text-base mb-5 border-b border-white/[0.06] pb-4"><i class="fa-solid fa-calculator text-violet-400 mr-2"></i>Payroll Rules</h3>
                        <div class="space-y-4">
                            <div><label class="text-sm font-medium text-zinc-300">Currency Symbol</label><input type="text" name="payroll_currency" value="<?php echo htmlspecialchars($settings['payroll_currency'] ?? '$'); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Overtime Rate (xBase)</label><input type="number" step="0.1" name="payroll_overtime_rate" value="<?php echo htmlspecialchars($settings['payroll_overtime_rate'] ?? '1.5'); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Tax (%)</label><input type="number" step="0.01" name="payroll_tax_percent" value="<?php echo htmlspecialchars($settings['payroll_tax_percent'] ?? '0'); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Working Days / Month</label><input type="number" name="payroll_working_days_per_month" value="<?php echo htmlspecialchars($settings['payroll_working_days_per_month'] ?? '22'); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Working Hours / Day</label><input type="number" step="0.5" name="payroll_working_hours_per_day" value="<?php echo htmlspecialchars($settings['payroll_working_hours_per_day'] ?? '8'); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-1">
                        <h3 class="font-bold text-white text-base mb-5 border-b border-white/[0.06] pb-4"><i class="fa-solid fa-clock text-violet-400 mr-2"></i>Attendance & Leave</h3>
                        <div class="space-y-4">
                            <div><label class="text-sm font-medium text-zinc-300">Late Threshold (minutes)</label><input type="number" name="late_threshold_minutes" value="<?php echo htmlspecialchars($settings['late_threshold_minutes'] ?? '15'); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Annual Leave Quota (days)</label><input type="number" name="leave_annual_quota" value="<?php echo htmlspecialchars($settings['leave_annual_quota'] ?? '14'); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                            <div><label class="text-sm font-medium text-zinc-300">Sick Leave Quota (days)</label><input type="number" name="sick_leave_quota" value="<?php echo htmlspecialchars($settings['sick_leave_quota'] ?? '7'); ?>" class="w-full mt-1 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"></div>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-2">
                        <h3 class="font-bold text-white text-base mb-5 border-b border-white/[0.06] pb-4"><i class="fa-solid fa-bell text-violet-400 mr-2"></i>Notifications</h3>
                        <div class="space-y-4">
                            <?php $notify_leave = $settings['notify_on_leave_request'] ?? '1'; ?>
                            <?php $notify_ot = $settings['notify_on_overtime_request'] ?? '1'; ?>
                            <?php $notify_register = $settings['notify_on_employee_register'] ?? '1'; ?>
                            <label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" name="notify_on_leave_request" value="1" <?php echo $notify_leave == '1' ? 'checked' : ''; ?> class="w-4 h-4 rounded bg-white/[0.06] border-white/10 text-violet-500 focus:ring-violet-500"><span class="text-sm text-zinc-300">Leave Requests</span></label>
                            <label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" name="notify_on_overtime_request" value="1" <?php echo $notify_ot == '1' ? 'checked' : ''; ?> class="w-4 h-4 rounded bg-white/[0.06] border-white/10 text-violet-500 focus:ring-violet-500"><span class="text-sm text-zinc-300">Overtime Requests</span></label>
                            <label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" name="notify_on_employee_register" value="1" <?php echo $notify_register == '1' ? 'checked' : ''; ?> class="w-4 h-4 rounded bg-white/[0.06] border-white/10 text-violet-500 focus:ring-violet-500"><span class="text-sm text-zinc-300">New Employee Registration</span></label>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex justify-end animate-fade-in-up">
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500 text-white font-semibold text-sm px-8 py-3 shadow-lg transition-all duration-200 hover:-translate-y-0.5">
                        <i class="fa-solid fa-floppy-disk mr-2"></i> Save All Settings
                    </button>
                </div>
            </form>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-6 lg:px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> AURA HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400"><span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span><span>System Secure</span></span>
        </footer>
    </div>
</body>
</html>
