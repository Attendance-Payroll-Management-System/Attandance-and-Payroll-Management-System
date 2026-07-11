<?php
if (!isset($sidebar_role)) {
    $script_path = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($script_path, '/admin/') !== false) {
        $sidebar_role = 'admin';
    } elseif (strpos($script_path, '/employee/') !== false) {
        $sidebar_role = 'employee';
    } else {
        $sidebar_role = 'admin';
    }
}

$current_page = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

$admin_name  = $admin_name  ?? ($_SESSION['admin_name'] ?? 'Admin');
$admin_title = $admin_title ?? 'HR Administrator';
$emp_name    = $emp_name    ?? ($_SESSION['employee_name'] ?? 'Employee');

$pending_leaves = 0;
$pending_ot = 0;
$pending_ot_assign = 0;
if ($sidebar_role === 'admin' && isset($conn) && $conn) {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'Pending'");
    $pending_leaves = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
    $has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;
    if ($has_source) {
        $res = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending' AND (source IS NULL OR source = 'employee_request')");
        $pending_ot = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
        $res = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending' AND source = 'admin_assigned'");
        $pending_ot_assign = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
    } else {
        $res = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE status = 'Pending'");
        $pending_ot = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
    }
}

function nav_active($pages, $current)
{
    return in_array($current, (array)$pages);
}

function nav_item($href, $label, $icon, $current, $pages = null, $badge = null, $color = 'navy')
{
    $active = $pages ? nav_active($pages, $current) : ($href === $current);

    $colors = [
        'navy'    => ['css' => 'sidebar-icon-navy', 'text' => 'text-blue-600', 'active_bg' => 'from-blue-500/20 to-cyan-500/10', 'hover_bg' => 'hover:bg-blue-50', 'dark_hover_bg' => 'dark:hover:bg-blue-500/10'],
        'indigo'  => ['css' => 'sidebar-icon-indigo', 'text' => 'text-indigo-500', 'active_bg' => 'from-indigo-500/20 to-blue-500/10', 'hover_bg' => 'hover:bg-indigo-50', 'dark_hover_bg' => 'dark:hover:bg-indigo-500/10'],
        'blue'    => ['css' => 'sidebar-icon-blue', 'text' => 'text-blue-500', 'active_bg' => 'from-blue-500/20 to-cyan-500/10', 'hover_bg' => 'hover:bg-blue-50', 'dark_hover_bg' => 'dark:hover:bg-blue-500/10'],
        'sky'     => ['css' => 'sidebar-icon-sky', 'text' => 'text-sky-500', 'active_bg' => 'from-sky-500/20 to-cyan-500/10', 'hover_bg' => 'hover:bg-sky-50', 'dark_hover_bg' => 'dark:hover:bg-sky-500/10'],
        'emerald' => ['css' => 'sidebar-icon-emerald', 'text' => 'text-emerald-500', 'active_bg' => 'from-emerald-500/20 to-teal-500/10', 'hover_bg' => 'hover:bg-emerald-50', 'dark_hover_bg' => 'dark:hover:bg-emerald-500/10'],
        'amber'   => ['css' => 'sidebar-icon-amber', 'text' => 'text-amber-500', 'active_bg' => 'from-amber-500/20 to-orange-500/10', 'hover_bg' => 'hover:bg-amber-50', 'dark_hover_bg' => 'dark:hover:bg-amber-500/10'],
        'rose'    => ['css' => 'sidebar-icon-rose', 'text' => 'text-rose-500', 'active_bg' => 'from-rose-500/20 to-pink-500/10', 'hover_bg' => 'hover:bg-rose-50', 'dark_hover_bg' => 'dark:hover:bg-rose-500/10'],
        'cyan'    => ['css' => 'sidebar-icon-cyan', 'text' => 'text-cyan-500', 'active_bg' => 'from-cyan-500/20 to-blue-500/10', 'hover_bg' => 'hover:bg-cyan-50', 'dark_hover_bg' => 'dark:hover:bg-cyan-500/10'],
        'purple'  => ['css' => 'sidebar-icon-purple', 'text' => 'text-purple-500', 'active_bg' => 'from-purple-500/20 to-indigo-500/10', 'hover_bg' => 'hover:bg-purple-50', 'dark_hover_bg' => 'dark:hover:bg-purple-500/10'],
        'orange'  => ['css' => 'sidebar-icon-orange', 'text' => 'text-orange-500', 'active_bg' => 'from-orange-500/20 to-red-500/10', 'hover_bg' => 'hover:bg-orange-50', 'dark_hover_bg' => 'dark:hover:bg-orange-500/10'],
        'slate'   => ['css' => 'sidebar-icon-slate', 'text' => 'text-slate-500', 'active_bg' => 'from-slate-500/20 to-gray-500/10', 'hover_bg' => 'hover:bg-slate-50', 'dark_hover_bg' => 'dark:hover:bg-slate-500/10'],
        'violet'  => ['css' => 'sidebar-icon-purple', 'text' => 'text-purple-500', 'active_bg' => 'from-purple-500/20 to-indigo-500/10', 'hover_bg' => 'hover:bg-purple-50', 'dark_hover_bg' => 'dark:hover:bg-purple-500/10'],
    ];

    $c = $colors[$color] ?? $colors['navy'];

    $classes = $active
        ? 'flex items-center gap-3 px-4 py-2.5 rounded-xl bg-gradient-to-r ' . $c['active_bg'] . ' text-slate-900 dark:text-white border border-blue-200 dark:border-white/[0.08] shadow-sm'
        : 'flex items-center gap-3 px-4 py-2.5 rounded-xl text-slate-500 dark:text-zinc-400 hover:text-slate-900 dark:hover:text-white ' . $c['hover_bg'] . ' ' . $c['dark_hover_bg'] . ' transition-all duration-200';

    $html = '<a href="' . $href . '" class="group nav-item ' . $classes . '">';
    $html .= '<div class="w-8 h-8 rounded-xl flex items-center justify-center ' . $c['css'] . ' text-white transition-all duration-300 group-hover:scale-110">';
    $html .= '<i class="fas fa-' . $icon . ' text-sm"></i></div>';
    $html .= '<span class="text-sm font-medium">' . $label . '</span>';
    if ($badge !== null && $badge > 0) {
        $html .= '<span class="ml-auto bg-rose-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full min-w-[20px] text-center shadow-lg shadow-rose-500/40 animate-pulse-soft">' . $badge . '</span>';
    }
    $html .= '</a>';
    return $html;
}

function nav_section($label, $icon, $children, $current_page, $badge = null, $color = 'navy')
{
    $child_pages = array_column($children, 'page');
    $open = nav_active($child_pages, $current_page);

    $colors = [
        'navy'    => ['css' => 'sidebar-icon-navy', 'active_text' => 'text-blue-600 dark:text-blue-300', 'active_bg' => 'bg-blue-500/10', 'border' => 'border-blue-400', 'dot' => 'bg-blue-400', 'hover_bg' => 'hover:bg-blue-50', 'dark_hover_bg' => 'dark:hover:bg-blue-500/10'],
        'indigo'  => ['css' => 'sidebar-icon-indigo', 'active_text' => 'text-indigo-600 dark:text-indigo-300', 'active_bg' => 'bg-indigo-500/10', 'border' => 'border-indigo-400', 'dot' => 'bg-indigo-400', 'hover_bg' => 'hover:bg-indigo-50', 'dark_hover_bg' => 'dark:hover:bg-indigo-500/10'],
        'blue'    => ['css' => 'sidebar-icon-blue', 'active_text' => 'text-blue-600 dark:text-blue-300', 'active_bg' => 'bg-blue-500/10', 'border' => 'border-blue-400', 'dot' => 'bg-blue-400', 'hover_bg' => 'hover:bg-blue-50', 'dark_hover_bg' => 'dark:hover:bg-blue-500/10'],
        'sky'     => ['css' => 'sidebar-icon-sky', 'active_text' => 'text-sky-600 dark:text-sky-300', 'active_bg' => 'bg-sky-500/10', 'border' => 'border-sky-400', 'dot' => 'bg-sky-400', 'hover_bg' => 'hover:bg-sky-50', 'dark_hover_bg' => 'dark:hover:bg-sky-500/10'],
        'emerald' => ['css' => 'sidebar-icon-emerald', 'active_text' => 'text-emerald-600 dark:text-emerald-300', 'active_bg' => 'bg-emerald-500/10', 'border' => 'border-emerald-400', 'dot' => 'bg-emerald-400', 'hover_bg' => 'hover:bg-emerald-50', 'dark_hover_bg' => 'dark:hover:bg-emerald-500/10'],
        'amber'   => ['css' => 'sidebar-icon-amber', 'active_text' => 'text-amber-600 dark:text-amber-300', 'active_bg' => 'bg-amber-500/10', 'border' => 'border-amber-400', 'dot' => 'bg-amber-400', 'hover_bg' => 'hover:bg-amber-50', 'dark_hover_bg' => 'dark:hover:bg-amber-500/10'],
        'rose'    => ['css' => 'sidebar-icon-rose', 'active_text' => 'text-rose-600 dark:text-rose-300', 'active_bg' => 'bg-rose-500/10', 'border' => 'border-rose-400', 'dot' => 'bg-rose-400', 'hover_bg' => 'hover:bg-rose-50', 'dark_hover_bg' => 'dark:hover:bg-rose-500/10'],
        'cyan'    => ['css' => 'sidebar-icon-cyan', 'active_text' => 'text-cyan-600 dark:text-cyan-300', 'active_bg' => 'bg-cyan-500/10', 'border' => 'border-cyan-400', 'dot' => 'bg-cyan-400', 'hover_bg' => 'hover:bg-cyan-50', 'dark_hover_bg' => 'dark:hover:bg-cyan-500/10'],
        'purple'  => ['css' => 'sidebar-icon-purple', 'active_text' => 'text-purple-600 dark:text-purple-300', 'active_bg' => 'bg-purple-500/10', 'border' => 'border-purple-400', 'dot' => 'bg-purple-400', 'hover_bg' => 'hover:bg-purple-50', 'dark_hover_bg' => 'dark:hover:bg-purple-500/10'],
        'orange'  => ['css' => 'sidebar-icon-orange', 'active_text' => 'text-orange-600 dark:text-orange-300', 'active_bg' => 'bg-orange-500/10', 'border' => 'border-orange-400', 'dot' => 'bg-orange-400', 'hover_bg' => 'hover:bg-orange-50', 'dark_hover_bg' => 'dark:hover:bg-orange-500/10'],
        'slate'   => ['css' => 'sidebar-icon-slate', 'active_text' => 'text-slate-600 dark:text-slate-300', 'active_bg' => 'bg-slate-500/10', 'border' => 'border-slate-400', 'dot' => 'bg-slate-400', 'hover_bg' => 'hover:bg-slate-50', 'dark_hover_bg' => 'dark:hover:bg-slate-500/10'],
        'violet'  => ['css' => 'sidebar-icon-purple', 'active_text' => 'text-purple-600 dark:text-purple-300', 'active_bg' => 'bg-purple-500/10', 'border' => 'border-purple-400', 'dot' => 'bg-purple-400', 'hover_bg' => 'hover:bg-purple-50', 'dark_hover_bg' => 'dark:hover:bg-purple-500/10'],
    ];

    $c = $colors[$color] ?? $colors['navy'];

    $html = '<div x-data="{ open: ' . ($open ? 'true' : 'false') . ' }" class="space-y-0.5">';
    $html .= '<button @click="open = !open" class="w-full flex items-center justify-between px-4 py-2.5 rounded-xl text-slate-500 dark:text-zinc-400 hover:text-slate-900 dark:hover:text-white ' . $c['hover_bg'] . ' ' . $c['dark_hover_bg'] . ' transition-all duration-200 group">';
    $html .= '<span class="flex items-center gap-3">';
    $html .= '<div class="w-8 h-8 rounded-xl flex items-center justify-center ' . $c['css'] . ' text-white group-hover:scale-110 transition-all duration-300">';
    $html .= '<i class="fas fa-' . $icon . ' text-sm"></i></div>';
    $html .= '<span class="text-sm font-medium text-slate-700 dark:text-zinc-300 group-hover:text-slate-900 dark:group-hover:text-white transition-colors">' . $label . '</span></span>';
    $html .= '<span class="flex items-center gap-1.5">';
    if ($badge !== null && $badge > 0) {
        $html .= '<span class="bg-rose-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full shadow-lg shadow-rose-500/40">' . $badge . '</span>';
    }
    $html .= '<i class="fas fa-chevron-down text-xs transition-transform duration-200" :class="{ \'rotate-180\': open }"></i></span>';
    $html .= '</button>';
    $html .= '<div x-show="open" x-transition:enter="transition-all duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition-all duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1" class="ml-3 pl-3 border-l-2 border-slate-200 dark:border-white/[0.08]">';
    $html .= '<div class="py-1 space-y-0.5">';
    foreach ($children as $child) {
        $child_active = $child['page'] === $current_page;
        if ($child_active) {
            $html .= '<a href="' . $child['href'] . '" class="flex items-center gap-2.5 px-3 py-2 rounded-lg ' . $c['active_text'] . ' ' . $c['active_bg'] . ' text-sm font-medium border-l-2 ' . $c['border'] . ' ml-[-1px]">';
            $html .= '<span class="w-2 h-2 rounded-full ' . $c['dot'] . ' animate-ping-soft"></span>' . $child['label'] . '</a>';
        } else {
            $html .= '<a href="' . $child['href'] . '" class="block px-3 py-2 rounded-lg text-slate-400 dark:text-zinc-500 hover:text-slate-700 dark:hover:text-zinc-200 ' . $c['hover_bg'] . ' ' . $c['dark_hover_bg'] . ' text-sm transition-all duration-150 ml-[-1px]">' . $child['label'] . '</a>';
        }
    }
    $html .= '</div></div></div>';
    return $html;
}
?>
<button @click="sidebarOpen = !sidebarOpen" class="fixed top-4 left-4 z-50 lg:hidden w-11 h-11 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center shadow-lg shadow-blue-600/30 hover:shadow-xl hover:shadow-blue-600/40 hover:scale-105 transition-all duration-200 animate-fade-in-left">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
    </svg>
</button>

<div x-show="sidebarOpen" @click="sidebarOpen = false" x-transition:enter="transition-opacity duration-300" x-transition:leave="transition-opacity duration-200" class="fixed inset-0 z-30 bg-black/70 backdrop-blur-sm lg:hidden" style="display: none;"></div>

<aside class="fixed inset-y-0 left-0 z-40 w-64 bg-white dark:bg-[#0F172A] text-slate-900 dark:text-white flex flex-col border-r border-slate-200 dark:border-blue-500/10 transform transition-all duration-300 ease-in-out -translate-x-full lg:translate-x-0 shadow-2xl shadow-black/10 dark:shadow-black/40" :class="{ 'translate-x-0': sidebarOpen }">

    <div class="relative overflow-hidden px-5 py-6 border-b border-slate-100 dark:border-white/[0.06]">
        <div class="absolute -inset-20 bg-gradient-to-br from-blue-500/30 via-indigo-500/20 to-cyan-500/10 dark:from-blue-500/30 dark:via-indigo-500/20 dark:to-cyan-500/10 blur-3xl opacity-60 dark:opacity-100"></div>
        <div class="absolute top-0 right-0 w-40 h-40 bg-blue-400/15 dark:bg-blue-400/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/4"></div>
        <div class="absolute bottom-0 left-0 w-32 h-32 bg-indigo-400/15 dark:bg-indigo-400/10 rounded-full blur-3xl translate-y-1/2 -translate-x-1/4"></div>
        <div class="relative flex items-center gap-3">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-600 via-indigo-600 to-blue-500 flex items-center justify-center shadow-xl shadow-blue-600/40 ring-2 ring-blue-200 dark:ring-white/20 sidebar-logo animate-float">
                <i class="fas fa-bolt text-white text-lg"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h1 class="text-base font-extrabold tracking-tight bg-gradient-to-r from-blue-700 via-indigo-600 to-blue-500 dark:from-blue-400 dark:via-indigo-400 dark:to-cyan-400 bg-clip-text text-transparent truncate">HNIN AKARI NWE</h1>
                <p class="text-[9px] font-bold text-blue-500/70 dark:text-blue-300/70 tracking-[0.25em] uppercase truncate">Payroll Management</p>
            </div>
        </div>
    </div>

    <div class="px-5 py-3 border-b border-slate-100 dark:border-white/[0.06] bg-gradient-to-r from-blue-50/80 to-indigo-50/80 dark:from-blue-500/5 dark:to-indigo-500/5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 via-indigo-500 to-blue-600 flex items-center justify-center text-xs font-bold text-white shadow-lg shadow-blue-500/30 shrink-0 ring-2 ring-blue-200 dark:ring-white/20">
                <?php echo strtoupper(substr($sidebar_role === 'admin' ? $admin_name : $emp_name, 0, 2)); ?>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($sidebar_role === 'admin' ? $admin_name : $emp_name); ?></p>
                <p class="text-[10px] text-slate-400 dark:text-zinc-500 truncate"><?php echo $sidebar_role === 'admin' ? 'Administrator' : 'Employee'; ?></p>
            </div>
            <button onclick="toggleTheme()" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 dark:text-zinc-500 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-500/10 transition-all duration-200 shrink-0" title="Toggle theme">
                <i class="fa-solid fa-sun text-sm dark:hidden"></i>
                <i class="fa-solid fa-moon text-sm hidden dark:inline"></i>
            </button>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5 sidebar-scrollbar">

        <?php if ($sidebar_role === 'admin'): ?>

            <div class="px-4 pb-2">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Main Menu</p>
            </div>
            <?php echo nav_item('dashboard.php', 'Dashboard', 'gauge-high', $current_page, null, null, 'blue'); ?>

            <div class="px-4 pt-3 pb-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Management</p>
            </div>
            <?php echo nav_section('Employees', 'users-gear', [
                ['page' => 'employee.php', 'href' => 'employee.php', 'label' => 'Employee List'],
                ['page' => 'insert1.php', 'href' => 'insert1.php', 'label' => 'Add Employee'],
                ['page' => 'edit_employee.php', 'href' => 'edit_employee.php', 'label' => 'Edit Employee'],
                ['page' => 'view_employee.php', 'href' => 'view_employee.php', 'label' => 'View Employee'],
            ], $current_page, null, 'navy'); ?>
            <?php echo nav_section('Attendance', 'calendar-check', [
                ['page' => 'attendance.php', 'href' => 'attendance.php', 'label' => 'Monthly Attendance'],
                ['page' => 'dailyattendance.php', 'href' => 'dailyattendance.php', 'label' => 'Daily Attendance'],
                ['page' => 'attendance_summary.php', 'href' => 'attendance_summary.php', 'label' => 'Attendance Summary'],
                ['page' => 'process_daily_attendance.php', 'href' => 'process_daily_attendance.php', 'label' => 'Process Attendance'],
            ], $current_page, null, 'emerald'); ?>
            <?php echo nav_section('Leave Management', 'paper-plane', [
                ['page' => 'leaveApproval.php', 'href' => 'leaveApproval.php', 'label' => 'Leave Approvals'],
                ['page' => 'leavereport.php', 'href' => 'leavereport.php', 'label' => 'Leave Report'],
            ], $current_page, $pending_leaves, 'amber'); ?>
            <?php echo nav_section('Overtime', 'stopwatch', [
                ['page' => 'overtimeApproval.php', 'href' => 'overtimeApproval.php', 'label' => 'OT Approvals'],
                ['page' => 'assign_overtime.php', 'href' => 'assign_overtime.php', 'label' => 'Assign OT'],
                ['page' => 'overtimereport.php', 'href' => 'overtimereport.php', 'label' => 'OT Report'],
            ], $current_page, $pending_ot, 'orange'); ?>
            <?php echo nav_section('Payroll', 'money-bill-wave', [
                ['page' => 'payroll.php', 'href' => 'payroll.php', 'label' => 'Generate Payroll'],
                ['page' => 'salaryreport.php', 'href' => 'salaryreport.php', 'label' => 'Salary Report'],
                ['page' => 'salary_slip.php', 'href' => 'salary_slip.php', 'label' => 'Salary Slips'],
                ['page' => 'bonous.php', 'href' => 'bonous.php', 'label' => 'Bonuses'],
                ['page' => 'deduction.php', 'href' => 'deduction.php', 'label' => 'Deductions'],
            ], $current_page, null, 'indigo'); ?>
            <div class="px-4 pt-3 pb-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Organization</p>
            </div>
            <?php echo nav_item('department.php', 'Departments', 'building', $current_page, null, null, 'blue'); ?>
            <?php echo nav_item('position.php', 'Positions', 'briefcase', $current_page, null, null, 'orange'); ?>
            <?php echo nav_item('holiday.php', 'Holidays', 'calendar-day', $current_page, null, null, 'cyan'); ?>
            <?php echo nav_item('policy.php', 'Company Policy', 'file-contract', $current_page, null, null, 'indigo'); ?>

            <?php echo nav_section('Reports', 'chart-column', [
                ['page' => 'reports.php', 'href' => 'reports.php', 'label' => 'Annual Report'],
                ['page' => 'salaryreport.php', 'href' => 'salaryreport.php', 'label' => 'Salary Report'],
                ['page' => 'salary_slip.php', 'href' => 'salary_slip.php', 'label' => 'Salary Slips'],
                ['page' => 'leavereport.php', 'href' => 'leavereport.php', 'label' => 'Leave Report'],
                ['page' => 'overtimereport.php', 'href' => 'overtimereport.php', 'label' => 'OT Report'],
            ], $current_page, null, 'rose'); ?>

            <div class="px-4 pt-3 pb-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Account</p>
            </div>
            <?php echo nav_item('profile.php', 'My Profile', 'circle-user', $current_page, null, null, 'navy'); ?>

            <div class="px-4 pt-3 pb-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">System</p>
            </div>
            <?php echo nav_item('settings.php', 'Settings', 'sliders', $current_page, null, null, 'slate'); ?>
            <?php echo nav_item('logout.php', 'Logout', 'right-from-bracket', $current_page, null, null, 'rose'); ?>

        <?php elseif ($sidebar_role === 'employee'): ?>

            <div class="px-4 pb-2">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Main Menu</p>
            </div>
            <?php echo nav_item('dashboard.php', 'Dashboard', 'house-chimney', $current_page, null, null, 'sky'); ?>
            <?php echo nav_item('attendance.php', 'Attendance', 'calendar-check', $current_page, null, null, 'emerald'); ?>
            <?php echo nav_item('attendance_summary.php', 'Attendance Summary', 'chart-simple', $current_page, null, null, 'cyan'); ?>
            <?php echo nav_item('leaverequest.php', 'Leave Request', 'paper-plane', $current_page, null, null, 'amber'); ?>
            <?php echo nav_item('overtimerequest.php', 'Overtime Request', 'stopwatch', $current_page, null, null, 'orange'); ?>
            <?php echo nav_item('attendanceall.php', 'My Records', 'folder-open', $current_page, null, null, 'cyan'); ?>
            <?php echo nav_item('company_policy.php', 'Company Policy', 'file-contract', $current_page, null, null, 'indigo'); ?>

            <div class="px-4 pt-3 pb-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Account</p>
            </div>
            <?php echo nav_item('profile.php', 'My Profile', 'circle-user', $current_page, null, null, 'navy'); ?>
            <?php echo nav_item('payroll.php', 'My Payroll', 'wallet', $current_page, null, null, 'emerald'); ?>
            <?php echo nav_item('change_password.php', 'Change Password', 'key', $current_page, null, null, 'amber'); ?>

            <div class="pt-2">
                <?php echo nav_item('login.php?logout=1', 'Logout', 'right-from-bracket', $current_page, null, null, 'rose'); ?>
            </div>

        <?php endif; ?>

    </nav>

</aside>
