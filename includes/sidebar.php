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

$admin_name  = $admin_name  ?? 'Admin';
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

function nav_item($href, $label, $icon, $current, $pages = null, $badge = null)
{
    $active = $pages ? nav_active($pages, $current) : ($href === $current);
    $classes = $active
        ? 'flex items-center gap-3 px-4 py-2.5 rounded-xl bg-gradient-to-r from-violet-500/20 to-fuchsia-500/10 text-violet-700 dark:text-white border border-violet-400/20 shadow-sm shadow-violet-500/10'
        : 'flex items-center gap-3 px-4 py-2.5 rounded-xl text-slate-500 dark:text-zinc-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-all duration-200';
    $html = '<a href="' . $href . '" class="group nav-item ' . $classes . '">';
    $html .= '<div class="w-8 h-8 rounded-lg flex items-center justify-center ' . ($active ? 'bg-gradient-to-br from-violet-500/30 to-fuchsia-500/20 text-violet-600 dark:text-violet-300' : 'bg-slate-100 dark:bg-white/[0.06] text-slate-400 dark:text-zinc-500 group-hover:bg-slate-200 dark:group-hover:bg-white/10 group-hover:text-slate-600 dark:group-hover:text-zinc-300') . ' transition-all duration-200">';
    $html .= '<i class="fas fa-' . $icon . ' text-xs"></i></div>';
    $html .= '<span class="text-sm font-medium">' . $label . '</span>';
    if ($badge !== null && $badge > 0) {
        $html .= '<span class="ml-auto bg-rose-500/90 text-white text-[10px] font-bold px-2 py-0.5 rounded-full min-w-[20px] text-center shadow-sm shadow-rose-500/20 animate-pulse-soft">' . $badge . '</span>';
    }
    $html .= '</a>';
    return $html;
}

function nav_section($label, $icon, $children, $current_page, $badge = null)
{
    $child_pages = array_column($children, 'page');
    $open = nav_active($child_pages, $current_page);
    $html = '<div x-data="{ open: ' . ($open ? 'true' : 'false') . ' }" class="space-y-0.5">';
    $html .= '<button @click="open = !open" class="w-full flex items-center justify-between px-4 py-2.5 rounded-xl text-slate-500 dark:text-zinc-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-all duration-200 group">';
    $html .= '<span class="flex items-center gap-3">';
    $html .= '<div class="w-8 h-8 rounded-lg flex items-center justify-center bg-slate-100 dark:bg-white/[0.06] text-slate-400 dark:text-zinc-500 group-hover:bg-slate-200 dark:group-hover:bg-white/10 group-hover:text-slate-600 dark:group-hover:text-zinc-300 transition-all duration-200">';
    $html .= '<i class="fas fa-' . $icon . ' text-xs"></i></div>';
    $html .= '<span class="text-sm font-medium text-slate-700 dark:text-zinc-300 group-hover:text-slate-900 dark:group-hover:text-white transition-colors">' . $label . '</span></span>';
    $html .= '<span class="flex items-center gap-1.5">';
    if ($badge !== null && $badge > 0) {
        $html .= '<span class="bg-rose-500/90 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">' . $badge . '</span>';
    }
    $html .= '<i class="fas fa-chevron-down text-xs transition-transform duration-200" :class="{ \'rotate-180\': open }"></i></span>';
    $html .= '</button>';
    $html .= '<div x-show="open" x-transition:enter="transition-all duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition-all duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1" class="ml-3 pl-3 border-l border-slate-200 dark:border-white/[0.06]">';
    $html .= '<div class="py-1 space-y-0.5">';
    foreach ($children as $child) {
        $child_active = $child['page'] === $current_page;
        if ($child_active) {
            $html .= '<a href="' . $child['href'] . '" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-violet-600 dark:text-violet-300 bg-violet-500/10 text-sm font-medium border-l-2 border-violet-400 ml-[-1px]">';
            $html .= '<span class="w-1 h-1 rounded-full bg-violet-400 animate-ping-soft"></span>' . $child['label'] . '</a>';
        } else {
            $html .= '<a href="' . $child['href'] . '" class="block px-3 py-2 rounded-lg text-slate-400 dark:text-zinc-500 hover:text-slate-700 dark:hover:text-zinc-200 hover:bg-slate-50 dark:hover:bg-white/[0.04] text-sm transition-all duration-150 ml-[-1px]">' . $child['label'] . '</a>';
        }
    }
    $html .= '</div></div></div>';
    return $html;
}
?>
<button @click="sidebarOpen = !sidebarOpen" class="fixed top-4 left-4 z-50 lg:hidden w-11 h-11 rounded-2xl bg-gradient-to-br from-violet-600 to-fuchsia-600 text-white flex items-center justify-center shadow-lg shadow-violet-600/30 hover:shadow-xl hover:shadow-violet-600/40 hover:scale-105 transition-all duration-200 animate-fade-in-left">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
    </svg>
</button>

<div x-show="sidebarOpen" @click="sidebarOpen = false" x-transition:enter="transition-opacity duration-300" x-transition:leave="transition-opacity duration-200" class="fixed inset-0 z-30 bg-black/70 backdrop-blur-sm lg:hidden" style="display: none;"></div>

<aside class="fixed inset-y-0 left-0 z-40 w-64 bg-white dark:bg-[#0a0a0f] text-slate-900 dark:text-white flex flex-col border-r border-slate-200 dark:border-white/[0.06] transform transition-all duration-300 ease-in-out -translate-x-full lg:translate-x-0 shadow-2xl shadow-black/10 dark:shadow-black/40" :class="{ 'translate-x-0': sidebarOpen }">

    <div class="relative overflow-hidden px-5 py-6 border-b border-slate-100 dark:border-white/[0.06]">
        <div class="absolute -inset-20 bg-gradient-to-br from-violet-500/20 via-fuchsia-500/10 to-transparent blur-3xl"></div>
        <div class="absolute top-0 right-0 w-40 h-40 bg-violet-400/5 rounded-full blur-3xl -translate-y-1/2 translate-x-1/4"></div>
        <div class="absolute bottom-0 left-0 w-32 h-32 bg-fuchsia-400/5 rounded-full blur-3xl translate-y-1/2 -translate-x-1/4"></div>
        <div class="relative flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 via-fuchsia-500 to-amber-500 flex items-center justify-center shadow-lg shadow-violet-500/30 ring-2 ring-white/10 animate-float">
                <i class="fas fa-bolt text-white text-base"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h1 class="text-sm font-extrabold tracking-tight text-slate-900 dark:text-white truncate">HNIN AKARI NWE</h1>
                <p class="text-[8px] font-semibold text-violet-600/50 dark:text-violet-300/50 tracking-[0.2em] uppercase truncate">Payroll Management</p>
            </div>
        </div>
    </div>

    <div class="px-5 py-3 border-b border-slate-100 dark:border-white/[0.06] bg-slate-50/50 dark:bg-white/[0.02]">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-amber-400 to-rose-500 flex items-center justify-center text-xs font-bold text-white shadow-sm shrink-0 ring-2 ring-white/5">
                <?php echo strtoupper(substr($sidebar_role === 'admin' ? $admin_name : $emp_name, 0, 2)); ?>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($sidebar_role === 'admin' ? $admin_name : $emp_name); ?></p>
                <p class="text-[10px] text-slate-400 dark:text-zinc-500 truncate"><?php echo $sidebar_role === 'admin' ? 'Administrator' : 'Employee'; ?></p>
            </div>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5 scrollbar-thin" style="scrollbar-width: none; -ms-overflow-style: none;">

        <?php if ($sidebar_role === 'admin'): ?>

            <div class="px-4 pb-2">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Main Menu</p>
            </div>
            <?php echo nav_item('dashboard.php', 'Dashboard', 'chart-pie', $current_page); ?>

            <div class="px-4 pt-3 pb-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Management</p>
            </div>
            <?php echo nav_section('Employee', 'users', [
                ['page' => 'employee.php', 'href' => 'employee.php', 'label' => 'Employee List'],
                ['page' => 'insert1.php', 'href' => 'insert1.php', 'label' => 'Add Employee'],
                ['page' => 'edit_employee.php', 'href' => 'edit_employee.php', 'label' => 'Edit Employee'],
                ['page' => 'view_employee.php', 'href' => 'view_employee.php', 'label' => 'View Employee'],
            ], $current_page); ?>
            <?php echo nav_section('Attendance', 'calendar-check', [
                ['page' => 'attendance.php', 'href' => 'attendance.php', 'label' => 'Monthly Attendance'],
                ['page' => 'dailyattendance.php', 'href' => 'dailyattendance.php', 'label' => 'Daily Attendance'],
            ], $current_page); ?>
            <?php echo nav_section('Leave', 'envelope-open-text', [
                ['page' => 'leaveApproval.php', 'href' => 'leaveApproval.php', 'label' => 'Leave Approvals'],
                ['page' => 'leavereport.php', 'href' => 'leavereport.php', 'label' => 'Leave Report'],
            ], $current_page, $pending_leaves); ?>
            <?php echo nav_section('Overtime', 'clock', [
                ['page' => 'overtimeApproval.php', 'href' => 'overtimeApproval.php', 'label' => 'OT Approvals'],
                ['page' => 'assign_overtime.php', 'href' => 'assign_overtime.php', 'label' => 'Assign OT'],
                ['page' => 'overtimereport.php', 'href' => 'overtimereport.php', 'label' => 'OT Report'],
            ], $current_page, $pending_ot); ?>
            <?php echo nav_section('Payroll', 'coins', [
                ['page' => 'payroll.php', 'href' => 'payroll.php', 'label' => 'Generate Payroll'],
                ['page' => 'salaryreport.php', 'href' => 'salaryreport.php', 'label' => 'Salary Report'],
                ['page' => 'salary_slip.php', 'href' => 'salary_slip.php', 'label' => 'Salary Slips'],
                ['page' => 'email_log.php', 'href' => 'email_log.php', 'label' => 'Email Log'],
                ['page' => 'bonous.php', 'href' => 'bonous.php', 'label' => 'Bonuses'],
                ['page' => 'deduction.php', 'href' => 'deduction.php', 'label' => 'Deductions'],
            ], $current_page); ?>
            <?php echo nav_section('Company', 'building', [
                ['page' => 'department.php', 'href' => 'department.php', 'label' => 'Departments'],
                ['page' => 'position.php', 'href' => 'position.php', 'label' => 'Positions'],
                ['page' => 'holiday.php', 'href' => 'holiday.php', 'label' => 'Holidays'],
            ], $current_page); ?>

            <?php echo nav_section('Reports', 'file-lines', [
                ['page' => 'reports.php', 'href' => 'reports.php', 'label' => 'Annual Report'],
                ['page' => 'salaryreport.php', 'href' => 'salaryreport.php', 'label' => 'Salary Report'],
                ['page' => 'salary_slip.php', 'href' => 'salary_slip.php', 'label' => 'Salary Slips'],
                ['page' => 'leavereport.php', 'href' => 'leavereport.php', 'label' => 'Leave Report'],
                ['page' => 'overtimereport.php', 'href' => 'overtimereport.php', 'label' => 'OT Report'],
            ], $current_page); ?>

            <div class="px-4 pt-3 pb-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">System</p>
            </div>
            <?php echo nav_item('settings.php', 'Settings', 'gear', $current_page); ?>
            <?php echo nav_item('logout.php', 'Logout', 'right-from-bracket', $current_page); ?>

        <?php elseif ($sidebar_role === 'employee'): ?>

            <div class="px-4 pb-2">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Main Menu</p>
            </div>
            <?php echo nav_item('dashboard.php', 'Dashboard', 'house', $current_page); ?>
            <?php echo nav_item('attendance.php', 'Attendance', 'calendar-check', $current_page); ?>
            <?php echo nav_item('leaverequest.php', 'Leave Request', 'envelope-open-text', $current_page); ?>
            <?php echo nav_item('overtimerequest.php', 'Overtime Request', 'clock', $current_page); ?>
            <?php echo nav_item('attendanceall.php', 'My Records', 'folder-open', $current_page); ?>

            <div class="px-4 pt-3 pb-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-zinc-500 tracking-[0.15em] uppercase">Account</p>
            </div>
            <?php echo nav_item('profile.php', 'My Profile', 'user', $current_page); ?>
            <?php echo nav_item('payroll.php', 'My Payroll', 'file-invoice-dollar', $current_page); ?>
            <?php echo nav_item('change_password.php', 'Change Password', 'key', $current_page); ?>

            <div class="pt-2">
                <?php echo nav_item('login.php?logout=1', 'Logout', 'right-from-bracket', $current_page); ?>
            </div>

        <?php endif; ?>

    </nav>

</aside>