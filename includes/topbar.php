<?php
$admin_name  = $admin_name  ?? ($_SESSION['admin_name'] ?? 'Admin');
$admin_email = $_SESSION['admin_email'] ?? 'admin@aura.hr';
$admin_photo = '';
$page_actions  = $page_actions  ?? '';

$current_page = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');

$page_info_map = [
    'dashboard.php'          => ['Dashboard',              'View system overview, employee statistics, attendance summaries, payroll summaries, and recent activities.'],
    'employee.php'           => ['Employee Directory',      'Manage employee records, personal information, departments, positions, and employee status.'],
    'insert1.php'            => ['Add Employee',            'Create new employee records with personal, company, and financial details.'],
    'edit_employee.php'      => ['Edit Employee',           'Update employee information, personal details, and employment records.'],
    'view_employee.php'      => ['Employee Profile',        'View detailed employee information, attendance, leave, and payroll history.'],
    'attendance.php'         => ['Attendance',              'Track employee check-in, check-out, late arrivals, attendance history, and daily attendance records.'],
    'dailyattendance.php'    => ['Daily Attendance',        'View and manage daily attendance records for all employees.'],
    'process_daily_attendance.php' => ['Process Attendance','Automatically process daily attendance, weekends, holidays, and absences.'],
    'leaveApproval.php'      => ['Leave Management',        'Manage employee leave requests, approvals, leave balances, and leave history.'],
    'leavereport.php'        => ['Leave Report',            'View and analyze employee leave records, balances, and reporting.'],
    'overtimeApproval.php'   => ['Overtime Management',     'Monitor overtime requests, approval status, overtime hours, and work schedules.'],
    'assign_overtime.php'    => ['Assign Overtime',         'Assign overtime work to employees and manage overtime schedules.'],
    'overtimereport.php'     => ['Overtime Report',         'View and analyze overtime records, hours, and approval reports.'],
    'payroll.php'            => ['Payroll',                 'Calculate salaries, allowances, deductions, bonuses, and generate salary slips.'],
    'salaryreport.php'       => ['Salary Report',           'View and analyze payroll summaries, salary reports, and financial data.'],
    'salary_slip.php'        => ['Salary Slips',            'Generate, view, and email employee salary slips.'],
    'bonous.php'             => ['Bonuses',                 'Manage employee bonuses, incentives, and additional compensation.'],
    'deduction.php'          => ['Deductions',              'Manage employee deductions, adjustments, and payroll deductions.'],
    'department.php'         => ['Departments',             'Manage company departments, structure, and employee distribution.'],
    'position.php'           => ['Positions',               'Manage job positions, roles, and organizational hierarchy.'],
    'holiday.php'            => ['Holidays',                'Manage company holidays, non-working days, and special dates.'],
    'reports.php'            => ['Reports',                 'View attendance reports, payroll reports, leave reports, overtime reports, and employee analytics.'],
    'profile.php'            => ['My Profile',              'View and manage your profile information, password, and account settings.'],
    'settings.php'           => ['Settings',                'Configure system settings, company information, payroll rules, and preferences.'],
    'email_log.php'          => ['Email Log',               'View email communication history, sent payslips, and notification logs.'],
];

$detected = $page_info_map[$current_page] ?? null;
$page_title  = $page_title  ?? ($detected[0] ?? 'HRMS');
$page_subtitle = $page_subtitle ?? ($detected[1] ?? '');
$unread_count = 0;
$topbar_notifications = [];

$is_admin = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false);
$topbar_emp_id = $is_admin ? null : ($_SESSION['employee_id'] ?? null);

if (isset($conn) && $conn) {
    require_once __DIR__ . '/../config/notifications.php';
    if ($topbar_emp_id) {
        $unread_count = get_unread_count($conn, $topbar_emp_id);
        $topbar_notifications = get_notifications($conn, $topbar_emp_id, 5);
    } else {
        $unread_count = get_unread_count($conn);
        $topbar_notifications = get_notifications($conn, null, 5);
    }
    $admin_id = $_SESSION['admin_id'] ?? null;
    if ($admin_id) {
        $res = $conn->query("SHOW COLUMNS FROM employee LIKE 'profile_photo'");
        if ($res && $res->num_rows > 0) {
            $res = $conn->query("SELECT profile_photo, email FROM employee WHERE id = " . (int)$admin_id);
            if ($res && $row = $res->fetch_assoc()) {
                $admin_photo = $row['profile_photo'] ?? '';
                if (!empty($row['email'])) $admin_email = $row['email'];
            }
        } else {
            $res = $conn->query("SELECT email FROM employee WHERE id = " . (int)$admin_id);
            if ($res && $row = $res->fetch_assoc()) {
                if (!empty($row['email'])) $admin_email = $row['email'];
            }
        }
    }
}

$short_name = explode(' ', $admin_name)[0];
?>
<header class="sticky top-0 z-20 bg-white/80 dark:bg-[#0a0a0f]/80 backdrop-blur-xl border-b border-slate-200 dark:border-white/[0.06]">
    <div class="flex items-center justify-between px-4 lg:px-8 h-16">
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($page_title); ?></h1>
            <?php if (!empty($page_subtitle)): ?>
            <p class="text-xs text-slate-500 dark:text-zinc-400 mt-0.5 truncate"><?php echo htmlspecialchars($page_subtitle); ?></p>
            <?php endif; ?>
        </div>
        <div class="flex items-center space-x-2 lg:space-x-3">

            <!-- Notifications -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="relative p-2.5 text-slate-500 dark:text-zinc-400 hover:text-violet-600 dark:hover:text-white bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] rounded-xl transition-all duration-200">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($unread_count > 0): ?>
                    <span class="absolute -top-0.5 -right-0.5 w-5 h-5 bg-rose-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center shadow-lg shadow-rose-500/30 animate-scale-in"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
                <div x-show="open" @click.outside="open = false"
                     x-transition:enter="transition-all duration-300 ease-out"
                     x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition-all duration-200 ease-in"
                     x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                     x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
                     class="absolute right-0 mt-2 w-80 lg:w-96 glass-strong rounded-xl shadow-xl border border-black/10 dark:border-white/10 z-50" style="display: none;">
                    <div class="p-3 border-b border-black/[0.06] dark:border-white/[0.06] flex items-center justify-between">
                        <h4 class="text-sm font-bold text-slate-900 dark:text-white"><i class="fa-regular fa-bell mr-1.5 text-violet-400"></i>Notifications</h4>
                        <?php if ($unread_count > 0): ?>
                        <a href="mark_notifications_read.php" class="text-[10px] font-semibold text-violet-600 dark:text-violet-400 hover:text-violet-700 dark:hover:text-violet-300 transition-colors">Mark all read</a>
                        <?php endif; ?>
                    </div>
                    <div class="max-h-96 overflow-y-auto">
                        <?php if (empty($topbar_notifications)): ?>
                        <div class="p-4 text-xs text-slate-500 dark:text-zinc-500 text-center">
                            <i class="fa-regular fa-bell-slash text-2xl text-slate-300 dark:text-zinc-600 block mb-2"></i>
                            <p>No notifications</p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($topbar_notifications as $noti): ?>
                            <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-black/[0.04] dark:border-white/[0.04] hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition <?php echo !$noti['is_read'] ? 'bg-violet-500/5 dark:bg-violet-500/10' : ''; ?>">
                                <p class="text-xs text-slate-700 dark:text-zinc-300"><?php echo htmlspecialchars($noti['message']); ?></p>
                                <p class="text-[10px] text-slate-400 dark:text-zinc-500 mt-1"><?php echo date('M d, h:i A', strtotime($noti['created_at'])); ?></p>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 border-t border-black/[0.06] dark:border-white/[0.06] text-center">
                        <a href="dashboard.php" class="text-xs font-semibold text-violet-600 dark:text-violet-400 hover:text-violet-700 dark:hover:text-violet-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>View Dashboard</a>
                    </div>
                </div>
            </div>

            <!-- Theme Toggle -->
            <button onclick="toggleTheme()" class="theme-toggle-btn">
                <i class="fa-solid fa-sun icon-sun text-base"></i>
                <i class="fa-solid fa-moon icon-moon text-base"></i>
            </button>

            <!-- Settings -->
            <a href="settings.php" class="p-2.5 text-slate-500 dark:text-zinc-400 hover:text-violet-600 dark:hover:text-white bg-slate-100 dark:bg-white/[0.06] hover:bg-slate-200 dark:hover:bg-white/[0.1] rounded-xl transition-all duration-200">
                <i class="fa-solid fa-gear text-lg"></i>
            </a>

            <!-- Admin Profile Dropdown -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="flex items-center gap-2 pl-1.5 pr-3 py-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-all duration-200">
                    <?php if (!empty($admin_photo)): ?>
                    <img src="../<?php echo htmlspecialchars($admin_photo); ?>" alt="" class="w-8 h-8 rounded-full object-cover ring-2 ring-violet-500/30">
                    <?php else: ?>
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-violet-500/20">
                        <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
                    </div>
                    <?php endif; ?>
                    <span class="text-sm font-semibold text-slate-700 dark:text-zinc-300 hidden sm:block"><?php echo htmlspecialchars($short_name); ?></span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400 dark:text-zinc-500 hidden sm:block transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                </button>

                <div x-show="open" @click.outside="open = false"
                     x-transition:enter="transition-all duration-200 ease-out"
                     x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition-all duration-150 ease-in"
                     x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                     x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
                     class="absolute right-0 mt-2 w-64 glass-strong rounded-2xl shadow-2xl border border-black/10 dark:border-white/10 z-50 overflow-hidden" style="display: none;">

                    <div class="p-4 bg-gradient-to-r from-violet-500/10 to-fuchsia-500/10 border-b border-black/[0.06] dark:border-white/[0.06]">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($admin_photo)): ?>
                            <img src="../<?php echo htmlspecialchars($admin_photo); ?>" alt="" class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-[#18181b] shadow-lg">
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-sm font-bold shadow-lg shadow-violet-500/20">
                                <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($admin_name); ?></p>
                                <p class="text-[11px] text-slate-500 dark:text-zinc-400 truncate"><?php echo htmlspecialchars($admin_email); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="p-2">
                        <a href="profile.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-colors">
                            <i class="fa-solid fa-user text-xs text-slate-400 dark:text-zinc-500 w-5 text-center"></i> View Profile
                        </a>
                        <a href="profile.php#password" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-colors">
                            <i class="fa-solid fa-lock text-xs text-slate-400 dark:text-zinc-500 w-5 text-center"></i> Change Password
                        </a>
                    </div>

                    <div class="p-2 border-t border-black/[0.06] dark:border-white/[0.06]">
                        <a href="<?php echo (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) ? 'logout.php' : '../admin/logout.php'; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-colors">
                            <i class="fa-solid fa-right-from-bracket text-xs w-5 text-center"></i> Logout
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</header>

<?php if (!empty($page_actions)): ?>
<div class="bg-white/60 dark:bg-[#0a0a0f]/60 border-b border-slate-200 dark:border-white/[0.06] px-4 lg:px-8 py-2.5 flex flex-wrap items-center justify-end gap-2">
    <div class="flex items-center gap-2"><?php echo $page_actions; ?></div>
</div>
<?php endif; ?>
