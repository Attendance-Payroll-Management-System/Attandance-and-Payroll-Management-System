<?php
/**
 * Admin Mobile Navigation Menu (Telegram-style)
 * Slide-in menu from the left for mobile screens.
 * Desktop sidebar remains unchanged.
 */
$current_page = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');

$admin_name  = $admin_name  ?? ($_SESSION['admin_name'] ?? 'Admin');
$admin_email = $_SESSION['admin_email'] ?? 'admin@company.com';
$admin_photo = '';

if (isset($conn) && $conn) {
    $admin_id = $_SESSION['admin_id'] ?? null;
    if ($admin_id) {
        $res = $conn->query("SELECT profile_photo, email FROM employee WHERE id = " . (int)$admin_id);
        if ($res && $row = $res->fetch_assoc()) {
            $admin_photo = $row['profile_photo'] ?? '';
            if (!empty($row['email'])) $admin_email = $row['email'];
        }
    }
}

$menu_items = [
    ['section' => ''],
    ['page' => 'dashboard.php',       'icon' => 'gauge-high',          'label' => 'Dashboard',           'href' => 'dashboard.php'],
    ['page' => 'employee.php',        'icon' => 'users',               'label' => 'Employee Directory',  'href' => 'employee.php'],
    ['page' => 'department.php',      'icon' => 'building',            'label' => 'Departments',         'href' => 'department.php'],
    ['page' => 'position.php',        'icon' => 'briefcase',           'label' => 'Positions',           'href' => 'position.php'],
    ['section' => 'Attendance & Leave'],
    ['page' => 'attendance.php',      'icon' => 'calendar-check',      'label' => 'Attendance',          'href' => 'attendance.php'],
    ['page' => 'dailyattendance.php', 'icon' => 'calendar-day',        'label' => 'Daily Attendance',    'href' => 'dailyattendance.php'],
    ['page' => 'leaveApproval.php',   'icon' => 'paper-plane',         'label' => 'Leave Management',    'href' => 'leaveApproval.php'],
    ['section' => 'Overtime & Payroll'],
    ['page' => 'overtimeApproval.php','icon' => 'stopwatch',            'label' => 'Overtime Management', 'href' => 'overtimeApproval.php'],
    ['page' => 'payroll.php',         'icon' => 'money-bill-wave',     'label' => 'Payroll Management',  'href' => 'payroll.php'],
    ['page' => 'salaryreport.php',    'icon' => 'chart-line',          'label' => 'Salary Report',       'href' => 'salaryreport.php'],
    ['section' => 'System'],
    ['page' => 'reports.php',         'icon' => 'chart-column',        'label' => 'Reports',             'href' => 'reports.php'],
    ['page' => 'settings.php',        'icon' => 'gear',                'label' => 'Settings',            'href' => 'settings.php'],
    ['page' => 'holiday.php',         'icon' => 'calendar-day',        'label' => 'Holidays',            'href' => 'holiday.php'],
    ['page' => 'policy.php',          'icon' => 'file-contract',       'label' => 'Company Policy',      'href' => 'policy.php'],
];

$page_map = [
    'dashboard.php'              => 'dashboard.php',
    'employee.php'               => 'employee.php',
    'insert1.php'                => 'employee.php',
    'edit_employee.php'          => 'employee.php',
    'view_employee.php'          => 'employee.php',
    'department.php'             => 'department.php',
    'position.php'               => 'position.php',
    'attendance.php'             => 'attendance.php',
    'dailyattendance.php'        => 'dailyattendance.php',
    'attendance_summary.php'     => 'attendance.php',
    'process_daily_attendance.php' => 'attendance.php',
    'leaveApproval.php'          => 'leaveApproval.php',
    'leavereport.php'            => 'leaveApproval.php',
    'overtimeApproval.php'       => 'overtimeApproval.php',
    'assign_overtime.php'        => 'overtimeApproval.php',
    'overtimereport.php'         => 'overtimeApproval.php',
    'payroll.php'                => 'payroll.php',
    'salaryreport.php'           => 'salaryreport.php',
    'salary_slip.php'            => 'payroll.php',
    'bonous.php'                 => 'payroll.php',
    'deduction.php'              => 'payroll.php',
    'reports.php'                => 'reports.php',
    'settings.php'               => 'settings.php',
    'holiday.php'                => 'holiday.php',
    'policy.php'                 => 'policy.php',
    'profile.php'                => 'settings.php',
];

$active_page = $page_map[$current_page] ?? $current_page;
?>
<!-- Admin Mobile Hamburger Button (mobile only) -->
<button id="adminMobileMenuBtn" onclick="openAdminMobileMenu()" class="fixed top-4 left-4 z-[60] md:hidden w-11 h-11 rounded-2xl bg-gradient-to-br from-indigo-600 to-indigo-500 text-white flex items-center justify-center shadow-lg shadow-indigo-600/30 hover:shadow-xl hover:shadow-indigo-600/40 hover:scale-105 active:scale-95 transition-all duration-200">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
    </svg>
</button>

<!-- Admin Mobile Backdrop -->
<div id="adminMobileBackdrop" onclick="closeAdminMobileMenu()" class="fixed inset-0 z-[70] bg-black/60 backdrop-blur-sm md:hidden opacity-0 pointer-events-none transition-opacity duration-300"></div>

<!-- Admin Mobile Menu (Telegram-style slide panel) -->
<div id="adminMobileMenu" class="fixed inset-y-0 left-0 z-[80] w-[300px] max-w-[85vw] bg-white dark:bg-[#0F172A] transform -translate-x-full transition-transform duration-300 ease-in-out md:hidden shadow-2xl flex flex-col">

    <!-- Menu Header - Profile -->
    <div class="relative overflow-hidden px-5 pt-6 pb-5 border-b border-slate-200/60 dark:border-white/[0.06] shrink-0">
        <div class="absolute -inset-20 bg-gradient-to-br from-indigo-500/20 via-blue-500/10 to-cyan-500/5 blur-3xl opacity-80"></div>
        <div class="relative flex items-center gap-3">
            <?php if (!empty($admin_photo)): ?>
            <img src="../<?php echo htmlspecialchars($admin_photo); ?>" alt="" class="w-12 h-12 rounded-full object-cover ring-2 ring-indigo-500/30 shadow-lg shrink-0">
            <?php else: ?>
            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-indigo-500 to-blue-600 text-white flex items-center justify-center text-sm font-bold shadow-lg shadow-indigo-500/30 shrink-0">
                <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
            </div>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-slate-900 dark:text-white leading-tight"><?php echo htmlspecialchars($admin_name); ?></p>
                <p class="text-xs text-slate-500 dark:text-zinc-400 mt-0.5 leading-tight"><?php echo htmlspecialchars($admin_email); ?></p>
                <span class="inline-block mt-1 text-[10px] font-semibold text-indigo-500 dark:text-indigo-400 bg-indigo-500/10 dark:bg-indigo-500/15 px-2 py-0.5 rounded-full">Administrator</span>
            </div>
        </div>
    </div>

    <!-- Menu Items (scrollable) -->
    <nav class="flex-1 overflow-y-auto py-2 px-2.5 admin-mobile-scrollbar">
        <?php foreach ($menu_items as $item): ?>
            <?php if (isset($item['section'])): ?>
                <?php if (!empty($item['section'])): ?>
                <div class="px-3 pt-4 pb-1.5">
                    <p class="text-[10px] font-bold text-slate-400 dark:text-zinc-500 tracking-wider uppercase"><?php echo $item['section']; ?></p>
                </div>
                <?php endif; ?>
            <?php else:
                $is_active = ($item['page'] === $active_page);
            ?>
            <a href="<?php echo $item['href']; ?>" onclick="closeAdminMobileMenu()" class="flex items-center gap-3 px-3 py-3 rounded-xl transition-all duration-200 group
                <?php echo $is_active
                    ? 'bg-indigo-500/10 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 font-semibold'
                    : 'text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.05] hover:text-slate-900 dark:hover:text-white'; ?>">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 transition-all duration-200
                    <?php echo $is_active
                        ? 'bg-indigo-500 text-white shadow-lg shadow-indigo-500/30'
                        : 'bg-slate-100 dark:bg-white/[0.06] text-slate-400 dark:text-zinc-500 group-hover:bg-indigo-100 dark:group-hover:bg-indigo-500/15 group-hover:text-indigo-500 dark:group-hover:text-indigo-400'; ?>">
                    <i class="fa-solid fa-<?php echo $item['icon']; ?> text-sm"></i>
                </div>
                <span class="text-[13px] font-medium leading-snug"><?php echo $item['label']; ?></span>
                <?php if ($is_active): ?>
                <div class="ml-auto w-2 h-2 rounded-full bg-indigo-500 shadow-lg shadow-indigo-500/50 shrink-0"></div>
                <?php endif; ?>
            </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <!-- Menu Footer -->
    <div class="px-2.5 py-3 border-t border-slate-200/60 dark:border-white/[0.06] shrink-0">
        <a href="logout.php" onclick="closeAdminMobileMenu()" class="flex items-center gap-3 px-3 py-3 rounded-xl text-rose-500 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-all duration-200">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-rose-100 dark:bg-rose-500/15 text-rose-500 dark:text-rose-400 shrink-0">
                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </div>
            <span class="text-[13px] font-medium">Logout</span>
        </a>
    </div>
</div>

<script>
function openAdminMobileMenu() {
    var menu = document.getElementById('adminMobileMenu');
    var backdrop = document.getElementById('adminMobileBackdrop');
    menu.classList.remove('-translate-x-full');
    menu.classList.add('translate-x-0');
    backdrop.classList.remove('opacity-0', 'pointer-events-none');
    backdrop.classList.add('opacity-100');
    document.body.style.overflow = 'hidden';
}

function closeAdminMobileMenu() {
    var menu = document.getElementById('adminMobileMenu');
    var backdrop = document.getElementById('adminMobileBackdrop');
    menu.classList.remove('translate-x-0');
    menu.classList.add('-translate-x-full');
    backdrop.classList.remove('opacity-100');
    backdrop.classList.add('opacity-0', 'pointer-events-none');
    document.body.style.overflow = '';
}
</script>
