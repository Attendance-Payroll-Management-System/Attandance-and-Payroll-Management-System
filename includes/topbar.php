<?php
$admin_name  = $admin_name  ?? ($_SESSION['admin_name'] ?? 'Admin');
$admin_email = $_SESSION['admin_email'] ?? 'admin@company.com';
$admin_photo = '';
$page_actions  = $page_actions  ?? '';

$current_page = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');

$page_info_map = [
    'dashboard.php'          => ['Dashboard',              'View system overview and recent activities.'],
    'employee.php'           => ['Employee Directory',      'Manage employee records and information.'],
    'insert1.php'            => ['Add Employee',            'Create new employee records.'],
    'edit_employee.php'      => ['Edit Employee',           'Update employee information.'],
    'view_employee.php'      => ['Employee Profile',        'View detailed employee information.'],
    'attendance.php'         => ['Attendance',              'Track check-in, check-out, and daily attendance.'],
    'dailyattendance.php'    => ['Daily Attendance',        'View daily attendance records.'],
    'attendance_summary.php' => ['Attendance Summary',      'View attendance summaries and analytics.'],
    'attendanceall.php'      => ['Attendance Records',      'Your complete attendance history.'],
    'process_daily_attendance.php' => ['Process Attendance','Process daily attendance records.'],
    'leaveApproval.php'      => ['Leave Management',        'Manage employee leave requests and approvals.'],
    'leaverequest.php'       => ['Leave Request',           'Submit and track your leave requests.'],
    'leavereport.php'        => ['Leave Report',            'View and analyze leave records.'],
    'overtimeApproval.php'   => ['Overtime Management',     'Monitor overtime requests and approvals.'],
    'overtimerequest.php'    => ['Overtime Request',        'Submit and track overtime requests.'],
    'assign_overtime.php'    => ['Assign Overtime',         'Assign overtime to employees.'],
    'overtimereport.php'     => ['Overtime Report',         'View overtime records and reports.'],
    'payroll.php'            => ['Payroll',                 'Calculate salaries and generate payslips.'],
    'salaryreport.php'       => ['Salary Report',           'View salary reports and financial data.'],
    'salary_slip.php'        => ['Salary Slips',            'Generate and view salary slips.'],
    'bonous.php'             => ['Bonuses',                 'Manage employee bonuses and incentives.'],
    'deduction.php'          => ['Deductions',              'Manage payroll deductions.'],
    'department.php'         => ['Departments',             'Manage company departments.'],
    'position.php'           => ['Positions',               'Manage job positions and roles.'],
    'holiday.php'            => ['Holidays',                'Manage company holidays.'],
    'policy.php'             => ['Company Policy',          'View and manage company policies.'],
    'reports.php'            => ['Reports',                 'View attendance and payroll reports.'],
    'profile.php'            => ['My Profile',              'Manage your personal information.'],
    'settings.php'           => ['Settings',                'Configure system settings.'],
    'monthly_attendance_report.php' => ['Monthly Attendance Report', 'Employee attendance with overtime overview.'],
    'company_policy.php'     => ['Company Policy',          'Review company rules and guidelines.'],
    'change_password.php'    => ['Change Password',         'Update your account password.'],
];

$detected = $page_info_map[$current_page] ?? null;
$page_title  = $page_title  ?? ($detected[0] ?? 'HRMS');
$page_subtitle = $page_subtitle ?? ($detected[1] ?? '');
$unread_count = 0;
$topbar_notifications = [];

$is_admin = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false);
// Admins are employees too — use their employee ID for notifications
$topbar_emp_id = $is_admin ? ($_SESSION['admin_id'] ?? null) : ($_SESSION['employee_id'] ?? null);

if (isset($conn) && $conn) {
    require_once __DIR__ . '/../config/notifications.php';
    if ($topbar_emp_id) {
        $unread_count = get_unread_count($conn, $topbar_emp_id);
        $topbar_notifications = get_notifications($conn, $topbar_emp_id, 5);
        $emp_stmt = $conn->prepare("SELECT name, email, profile_photo FROM employee WHERE id = ?");
        $emp_stmt->bind_param("i", $topbar_emp_id);
        $emp_stmt->execute();
        $emp_profile = $emp_stmt->get_result()->fetch_assoc();
        $emp_stmt->close();
        if ($emp_profile) {
            $admin_name = $emp_profile['name'] ?? $admin_name;
            if (!empty($emp_profile['email'])) $admin_email = $emp_profile['email'];
            $admin_photo = $emp_profile['profile_photo'] ?? '';
        }
    } else {
        $unread_count = get_unread_count($conn);
        $topbar_notifications = get_notifications($conn, null, 5);
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
}

$short_name = explode(' ', $admin_name)[0];

// ── Checkout Reminder Count (employee pages only) ──
$checkout_reminder_count = 0;
if (!$is_admin && $topbar_emp_id && isset($conn) && $conn) {
    $checkout_reminder_count = get_unread_checkout_reminder_count($conn, $topbar_emp_id);
}
?>
<?php if ($is_admin): ?>
    <div aria-hidden="true" class="h-16 w-full flex-shrink-0"></div>
<?php else: ?>
    <div aria-hidden="true" class="h-16 w-full flex-shrink-0"></div>
<?php endif; ?>

<header class="fixed top-0 right-0 z-30 <?php echo $is_admin ? 'admin-topbar' : 'emp-header'; ?> bg-white/90 dark:bg-[#0F172A]/90 border-b border-slate-200 dark:border-white/[0.06] backdrop-blur-xl">
    <div class="flex items-center justify-between h-16 px-4 lg:px-6">
        <!-- Left: Page Title & Subtitle -->
        <div class="flex-1 min-w-0">
            <h1 class="text-lg font-bold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($page_title); ?></h1>
            <?php if (!empty($page_subtitle)): ?>
            <p class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5"><?php echo htmlspecialchars($page_subtitle); ?></p>
            <?php endif; ?>
        </div>

        <!-- Right: Actions -->
        <div class="flex items-center gap-1.5 lg:gap-2 ml-4">

            <!-- Notifications -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="relative p-2.5 text-slate-500 dark:text-slate-400 hover:text-sky-600 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/[0.06] rounded-xl transition-all duration-200">
                    <i class="fa-solid fa-bell text-base"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="absolute top-1.5 right-1.5 w-4 h-4 bg-rose-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center shadow-lg shadow-rose-500/30 animate-pulse-soft"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                    <?php if ($checkout_reminder_count > 0): ?>
                        <span id="checkout-topbar-badge" class="absolute top-1 right-1 w-2.5 h-2.5 bg-amber-500 rounded-full animate-pulse border border-white dark:border-[#0F172A]" style="display: block;"></span>
                    <?php else: ?>
                        <span id="checkout-topbar-badge" class="absolute top-1 right-1 w-2.5 h-2.5 bg-amber-500 rounded-full animate-pulse border border-white dark:border-[#0F172A]" style="display: none;"></span>
                    <?php endif; ?>
                </button>
                <div x-show="open" @click.outside="open = false"
                    x-transition:enter="transition-all duration-200 ease-out"
                    x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="transition-all duration-150 ease-in"
                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                    x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
                    class="absolute right-0 mt-2 w-[calc(100vw-2rem)] max-w-80 lg:w-96 bg-white dark:bg-[#1E293B] rounded-2xl shadow-xl border border-slate-200 dark:border-white/[0.06] z-50 overflow-hidden" style="display: none;">
                    <div class="px-4 py-3 border-b border-slate-100 dark:border-white/[0.06] flex items-center justify-between">
                        <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Notifications</h4>
                        <?php if ($unread_count > 0): ?>
                            <a href="mark_notifications_read.php" class="text-xs font-medium text-sky-600 dark:text-sky-400 hover:text-sky-700 dark:hover:text-sky-300 transition-colors">Mark all read</a>
                        <?php endif; ?>
                    </div>
                    <div class="max-h-80 overflow-y-auto">
                        <?php if (!$is_admin && $checkout_reminder_count > 0): ?>
                        <div id="checkout-reminder-dropdown-item" class="px-4 py-3 border-b border-amber-100 dark:border-amber-500/20 bg-amber-50/50 dark:bg-amber-500/5">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></span>
                                <p class="text-xs font-semibold text-amber-700 dark:text-amber-400">Checkout Reminder</p>
                            </div>
                            <p class="text-xs text-slate-600 dark:text-slate-400">You haven't checked out yet today.</p>
                            <a href="attendance.php" class="inline-block mt-2 text-[11px] font-semibold text-amber-600 dark:text-amber-400 hover:underline">Check Out Now &rarr;</a>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($topbar_notifications)): ?>
                            <div class="p-6 text-center">
                                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-slate-100 dark:bg-white/[0.05] flex items-center justify-center">
                                    <i class="fa-regular fa-bell-slash text-xl text-slate-300 dark:text-slate-600"></i>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400 font-medium">No notifications yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($topbar_notifications as $noti): ?>
                                <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-slate-50 dark:border-white/[0.04] hover:bg-slate-50 dark:hover:bg-white/[0.02] transition-colors <?php echo !$noti['is_read'] ? 'bg-sky-50/50 dark:bg-sky-500/5' : ''; ?>">
                                    <p class="text-xs text-slate-700 dark:text-slate-300 leading-relaxed"><?php echo htmlspecialchars($noti['message']); ?></p>
                                    <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-1"><?php echo date('M d, h:i A', strtotime($noti['created_at'])); ?></p>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="px-4 py-2.5 border-t border-slate-100 dark:border-white/[0.06] text-center">
                        <a href="dashboard.php" class="text-xs font-medium text-sky-600 dark:text-sky-400 hover:text-sky-700 dark:hover:text-sky-300 transition-colors">View Dashboard</a>
                    </div>
                </div>
            </div>

            <!-- Dark Mode Toggle -->
            <button onclick="toggleTheme()" class="p-2.5 text-slate-500 dark:text-slate-400 hover:text-sky-600 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/[0.06] rounded-xl transition-all duration-200">
                <i class="fa-solid fa-sun text-base theme-icon-sun"></i>
                <i class="fa-solid fa-moon text-base theme-icon-moon" style="display:none;"></i>
            </button>

            <!-- Settings (admin only) -->
            <?php if ($is_admin): ?>
                <a href="settings.php" class="p-2.5 text-slate-500 dark:text-slate-400 hover:text-sky-600 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/[0.06] rounded-xl transition-all duration-200 hidden sm:flex">
                    <i class="fa-solid fa-gear text-base"></i>
                </a>
            <?php endif; ?>

            <!-- Profile Dropdown -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="flex items-center gap-2.5 pl-1.5 pr-2.5 py-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-all duration-200">
                    <?php if (!empty($admin_photo)): ?>
                        <img src="../<?php echo htmlspecialchars($admin_photo); ?>" alt="" class="w-8 h-8 rounded-xl object-cover border border-slate-200 dark:border-white/[0.06] shadow-sm">
                    <?php else: ?>
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-sky-500 to-blue-500 text-white flex items-center justify-center text-xs font-bold shadow-sm shadow-sky-500/20">
                            <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
                        </div>
                    <?php endif; ?>
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300 hidden sm:block"><?php echo htmlspecialchars($short_name); ?></span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400 dark:text-slate-500 hidden sm:block transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                </button>

                <div x-show="open" @click.outside="open = false"
                    x-transition:enter="transition-all duration-200 ease-out"
                    x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="transition-all duration-150 ease-in"
                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                    x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
                    class="absolute right-0 mt-2 w-56 bg-white dark:bg-[#1E293B] rounded-2xl shadow-xl border border-slate-200 dark:border-white/[0.06] z-50 overflow-hidden" style="display: none;">

                    <!-- Profile Header -->
                    <div class="px-4 py-3 border-b border-slate-100 dark:border-white/[0.06] bg-slate-50 dark:bg-white/[0.02]">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($admin_photo)): ?>
                                <img src="../<?php echo htmlspecialchars($admin_photo); ?>" alt="" class="w-10 h-10 rounded-xl object-cover border border-slate-200 dark:border-white/[0.06] shadow-sm">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500 to-blue-500 text-white flex items-center justify-center text-sm font-bold shadow-sm shadow-sky-500/20">
                                    <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($admin_name); ?></p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate"><?php echo htmlspecialchars($admin_email); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Menu Items -->
                    <div class="py-1">
                        <a href="profile.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/[0.03] transition-colors">
                            <i class="fa-solid fa-user text-xs text-slate-400 dark:text-slate-500 w-4 text-center"></i> View Profile
                        </a>
                        <a href="profile.php#password" class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-white/[0.03] transition-colors">
                            <i class="fa-solid fa-lock text-xs text-slate-400 dark:text-slate-500 w-4 text-center"></i> Change Password
                        </a>
                    </div>

                    <!-- Logout -->
                    <div class="py-1 border-t border-slate-100 dark:border-white/[0.06]">
                        <a href="<?php echo $is_admin ? 'logout.php' : '../admin/logout.php'; ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-colors">
                            <i class="fa-solid fa-right-from-bracket text-xs w-4 text-center"></i> Logout
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</header>

<?php if (!empty($page_actions)): ?>
    <div class="bg-white dark:bg-[#0F172A] border-b border-slate-200 dark:border-white/[0.06] px-4 lg:px-8 py-2.5 flex flex-wrap items-center justify-end gap-2">
        <div class="flex items-center gap-2"><?php echo $page_actions; ?></div>
    </div>
<?php endif; ?>
