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
$emp_position_name = $emp_position_name ?? 'Employee';
$emp_photo_path = $emp_photo_path ?? '';
$emp_department_name = $emp_department_name ?? '';

if ($sidebar_role === 'employee' && isset($conn) && $conn && empty($emp_photo_path)) {
    $emp_id = $_SESSION['employee_id'] ?? null;
    if ($emp_id) {
        $emp_q = $conn->prepare("SELECT e.name, e.profile_photo, e.employee_code, p.position_name, d.department_name FROM employee e LEFT JOIN positions p ON e.position_id = p.id LEFT JOIN departments d ON e.department_id = d.id WHERE e.id = ?");
        $emp_q->bind_param("i", $emp_id);
        $emp_q->execute();
        $emp_row = $emp_q->get_result()->fetch_assoc();
        $emp_q->close();
        if ($emp_row) {
            $emp_name = $emp_row['name'] ?? $emp_name;
            $emp_photo_path = $emp_row['profile_photo'] ?? '';
            $emp_position_name = $emp_row['position_name'] ?? 'Employee';
            $emp_department_name = $emp_row['department_name'] ?? '';
        }
    }
}

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

function nav_item($href, $label, $icon, $current, $pages = null, $badge = null, $color = 'indigo')
{
    $active = $pages ? nav_active($pages, $current) : ($href === $current);

    $colors = [
        'indigo'  => ['icon_bg' => 'bg-indigo-500', 'icon_hover' => 'group-hover:bg-indigo-600', 'active_bg' => 'bg-indigo-50 dark:bg-indigo-500/10', 'active_text' => 'text-indigo-700 dark:text-indigo-300', 'active_border' => 'border-indigo-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'emerald' => ['icon_bg' => 'bg-emerald-500', 'icon_hover' => 'group-hover:bg-emerald-600', 'active_bg' => 'bg-emerald-50 dark:bg-emerald-500/10', 'active_text' => 'text-emerald-700 dark:text-emerald-300', 'active_border' => 'border-emerald-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'teal'    => ['icon_bg' => 'bg-teal-500', 'icon_hover' => 'group-hover:bg-teal-600', 'active_bg' => 'bg-teal-50 dark:bg-teal-500/10', 'active_text' => 'text-teal-700 dark:text-teal-300', 'active_border' => 'border-teal-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'sky'     => ['icon_bg' => 'bg-sky-500', 'icon_hover' => 'group-hover:bg-sky-600', 'active_bg' => 'bg-sky-50 dark:bg-sky-500/10', 'active_text' => 'text-sky-700 dark:text-sky-300', 'active_border' => 'border-sky-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'amber'   => ['icon_bg' => 'bg-amber-500', 'icon_hover' => 'group-hover:bg-amber-600', 'active_bg' => 'bg-amber-50 dark:bg-amber-500/10', 'active_text' => 'text-amber-700 dark:text-amber-300', 'active_border' => 'border-amber-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'rose'    => ['icon_bg' => 'bg-rose-500', 'icon_hover' => 'group-hover:bg-rose-600', 'active_bg' => 'bg-rose-50 dark:bg-rose-500/10', 'active_text' => 'text-rose-700 dark:text-rose-300', 'active_border' => 'border-rose-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'orange'  => ['icon_bg' => 'bg-orange-500', 'icon_hover' => 'group-hover:bg-orange-600', 'active_bg' => 'bg-orange-50 dark:bg-orange-500/10', 'active_text' => 'text-orange-700 dark:text-orange-300', 'active_border' => 'border-orange-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'cyan'    => ['icon_bg' => 'bg-cyan-500', 'icon_hover' => 'group-hover:bg-cyan-600', 'active_bg' => 'bg-cyan-50 dark:bg-cyan-500/10', 'active_text' => 'text-cyan-700 dark:text-cyan-300', 'active_border' => 'border-cyan-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'purple'  => ['icon_bg' => 'bg-purple-500', 'icon_hover' => 'group-hover:bg-purple-600', 'active_bg' => 'bg-purple-50 dark:bg-purple-500/10', 'active_text' => 'text-purple-700 dark:text-purple-300', 'active_border' => 'border-purple-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'slate'   => ['icon_bg' => 'bg-slate-500', 'icon_hover' => 'group-hover:bg-slate-600', 'active_bg' => 'bg-slate-50 dark:bg-slate-500/10', 'active_text' => 'text-slate-700 dark:text-slate-300', 'active_border' => 'border-slate-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
    ];

    $c = $colors[$color] ?? $colors['indigo'];
    $base = 'group nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 relative';

    if ($active) {
        $classes = $base . ' ' . $c['active_bg'] . ' ' . $c['active_text'] . ' font-semibold border-l-[3px] ' . $c['active_border'] . ' ml-0';
    } else {
        $classes = $base . ' text-slate-600 dark:text-slate-400 ' . $c['hover_bg'] . ' hover:text-slate-900 dark:hover:text-white';
    }

    $html = '<a href="' . $href . '" class="' . $classes . '" title="' . htmlspecialchars($label) . '">';
    $html .= '<div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 transition-all duration-200 ' . ($active ? $c['icon_bg'] . ' text-white shadow-sm' : 'bg-slate-100 dark:bg-white/[0.06] text-slate-500 dark:text-slate-400 ' . $c['icon_hover'] . ' group-hover:text-white') . '">';
    $html .= '<i class="fas fa-' . $icon . ' text-sm"></i></div>';
    $html .= '<span class="nav-text text-[13px] whitespace-nowrap overflow-hidden">' . $label . '</span>';
    if ($badge !== null && $badge > 0) {
        $html .= '<span class="nav-badge ml-auto bg-rose-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center shrink-0">' . $badge . '</span>';
    }
    $html .= '</a>';
    return $html;
}

function nav_section($label, $icon, $children, $current_page, $badge = null, $color = 'indigo')
{
    $child_pages = array_column($children, 'page');
    $open = nav_active($child_pages, $current_page);

    $colors = [
        'indigo'  => ['icon_bg' => 'bg-indigo-500', 'icon_hover' => 'group-hover:bg-indigo-600', 'active_bg' => 'bg-indigo-50 dark:bg-indigo-500/10', 'active_text' => 'text-indigo-700 dark:text-indigo-300', 'active_border' => 'border-indigo-500', 'child_active' => 'text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10', 'child_dot' => 'bg-indigo-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'emerald' => ['icon_bg' => 'bg-emerald-500', 'icon_hover' => 'group-hover:bg-emerald-600', 'active_bg' => 'bg-emerald-50 dark:bg-emerald-500/10', 'active_text' => 'text-emerald-700 dark:text-emerald-300', 'active_border' => 'border-emerald-500', 'child_active' => 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-500/10', 'child_dot' => 'bg-emerald-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'teal'    => ['icon_bg' => 'bg-teal-500', 'icon_hover' => 'group-hover:bg-teal-600', 'active_bg' => 'bg-teal-50 dark:bg-teal-500/10', 'active_text' => 'text-teal-700 dark:text-teal-300', 'active_border' => 'border-teal-500', 'child_active' => 'text-teal-600 dark:text-teal-400 bg-teal-50 dark:bg-teal-500/10', 'child_dot' => 'bg-teal-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'sky'     => ['icon_bg' => 'bg-sky-500', 'icon_hover' => 'group-hover:bg-sky-600', 'active_bg' => 'bg-sky-50 dark:bg-sky-500/10', 'active_text' => 'text-sky-700 dark:text-sky-300', 'active_border' => 'border-sky-500', 'child_active' => 'text-sky-600 dark:text-sky-400 bg-sky-50 dark:bg-sky-500/10', 'child_dot' => 'bg-sky-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'amber'   => ['icon_bg' => 'bg-amber-500', 'icon_hover' => 'group-hover:bg-amber-600', 'active_bg' => 'bg-amber-50 dark:bg-amber-500/10', 'active_text' => 'text-amber-700 dark:text-amber-300', 'active_border' => 'border-amber-500', 'child_active' => 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10', 'child_dot' => 'bg-amber-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'rose'    => ['icon_bg' => 'bg-rose-500', 'icon_hover' => 'group-hover:bg-rose-600', 'active_bg' => 'bg-rose-50 dark:bg-rose-500/10', 'active_text' => 'text-rose-700 dark:text-rose-300', 'active_border' => 'border-rose-500', 'child_active' => 'text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-500/10', 'child_dot' => 'bg-rose-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'orange'  => ['icon_bg' => 'bg-orange-500', 'icon_hover' => 'group-hover:bg-orange-600', 'active_bg' => 'bg-orange-50 dark:bg-orange-500/10', 'active_text' => 'text-orange-700 dark:text-orange-300', 'active_border' => 'border-orange-500', 'child_active' => 'text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-500/10', 'child_dot' => 'bg-orange-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'cyan'    => ['icon_bg' => 'bg-cyan-500', 'icon_hover' => 'group-hover:bg-cyan-600', 'active_bg' => 'bg-cyan-50 dark:bg-cyan-500/10', 'active_text' => 'text-cyan-700 dark:text-cyan-300', 'active_border' => 'border-cyan-500', 'child_active' => 'text-cyan-600 dark:text-cyan-400 bg-cyan-50 dark:bg-cyan-500/10', 'child_dot' => 'bg-cyan-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'purple'  => ['icon_bg' => 'bg-purple-500', 'icon_hover' => 'group-hover:bg-purple-600', 'active_bg' => 'bg-purple-50 dark:bg-purple-500/10', 'active_text' => 'text-purple-700 dark:text-purple-300', 'active_border' => 'border-purple-500', 'child_active' => 'text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-500/10', 'child_dot' => 'bg-purple-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
        'slate'   => ['icon_bg' => 'bg-slate-500', 'icon_hover' => 'group-hover:bg-slate-600', 'active_bg' => 'bg-slate-50 dark:bg-slate-500/10', 'active_text' => 'text-slate-700 dark:text-slate-300', 'active_border' => 'border-slate-500', 'child_active' => 'text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-500/10', 'child_dot' => 'bg-slate-500', 'hover_bg' => 'hover:bg-slate-50 dark:hover:bg-white/[0.03]'],
    ];

    $c = $colors[$color] ?? $colors['indigo'];
    $section_active = nav_active($child_pages, $current_page);

    $html = '<div x-data="{ open: ' . ($open ? 'true' : 'false') . ' }" class="nav-section">';
    $html .= '<button @click="open = !open" class="group w-full flex items-center justify-between px-3 py-2.5 rounded-xl transition-all duration-200 ' . ($section_active ? $c['active_bg'] . ' ' . $c['active_text'] . ' font-semibold border-l-[3px] ' . $c['active_border'] . ' ml-0' : 'text-slate-600 dark:text-slate-400 ' . $c['hover_bg'] . ' hover:text-slate-900 dark:hover:text-white') . '">';
    $html .= '<span class="flex items-center gap-3">';
    $html .= '<div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 transition-all duration-200 ' . ($section_active ? $c['icon_bg'] . ' text-white shadow-sm' : 'bg-slate-100 dark:bg-white/[0.06] text-slate-500 dark:text-slate-400 ' . $c['icon_hover'] . ' group-hover:text-white') . '">';
    $html .= '<i class="fas fa-' . $icon . ' text-sm"></i></div>';
    $html .= '<span class="nav-text text-[13px] whitespace-nowrap overflow-hidden">' . $label . '</span></span>';
    $html .= '<span class="nav-badge flex items-center gap-1.5">';
    if ($badge !== null && $badge > 0) {
        $html .= '<span class="bg-rose-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full shrink-0">' . $badge . '</span>';
    }
    $html .= '<i class="nav-text fas fa-chevron-down text-[10px] transition-transform duration-200" :class="{ \'rotate-180\': open }"></i></span>';
    $html .= '</button>';
    $html .= '<div x-show="open" x-transition:enter="transition-all duration-200 ease-out" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition-all duration-150 ease-in" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1" class="nav-text">';
    $html .= '<div class="ml-3 pl-3 border-l border-slate-200 dark:border-white/[0.06] py-1 space-y-0.5">';
    foreach ($children as $child) {
        $child_active = $child['page'] === $current_page;
        if ($child_active) {
            $html .= '<a href="' . $child['href'] . '" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-[13px] font-medium ' . $c['child_active'] . '">';
            $html .= '<span class="w-1.5 h-1.5 rounded-full ' . $c['child_dot'] . ' shrink-0"></span>' . $child['label'] . '</a>';
        } else {
            $html .= '<a href="' . $child['href'] . '" class="block px-3 py-1.5 rounded-lg text-[13px] text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-50 dark:hover:bg-white/[0.03] transition-colors duration-150">' . $child['label'] . '</a>';
        }
    }
    $html .= '</div></div></div>';
    return $html;
}
?>

<?php
if ($sidebar_role === 'admin') {
    include __DIR__ . '/admin_mobile_menu.php';
}
?>

<!-- Sidebar (desktop & tablet) -->
<aside class="emp-sidebar fixed inset-y-0 left-0 z-40 w-64 bg-white dark:bg-[#0F172A] text-slate-900 dark:text-white flex-col border-r border-slate-200 dark:border-white/[0.06]">

    <!-- Logo / Brand -->
    <div class="flex items-center gap-3 px-5 h-16 border-b border-slate-200 dark:border-white/[0.06] shrink-0">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-sky-500 to-blue-500 flex items-center justify-center shrink-0 shadow-lg shadow-sky-500/20 sidebar-logo">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
        </div>
        <div class="sidebar-brand-text min-w-0 flex-1 overflow-hidden">
            <h1 class="text-sm font-bold text-slate-900 dark:text-white truncate leading-tight">HNIN AKARI NWE</h1>
            <p class="text-[10px] text-slate-400 dark:text-slate-500 font-medium truncate leading-tight">HR Management</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-0.5 sidebar-scrollbar">

        <?php if ($sidebar_role === 'admin'): ?>

            <div class="nav-text px-3 pb-1.5 pt-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 tracking-wider uppercase">Main Menu</p>
            </div>
            <?php echo nav_item('dashboard.php', 'Dashboard', 'gauge-high', $current_page, null, null, 'indigo'); ?>

            <div class="nav-text px-3 pt-4 pb-1.5">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 tracking-wider uppercase">Management</p>
            </div>
            <?php echo nav_section('Employees', 'users-gear', [
                ['page' => 'employee.php', 'href' => 'employee.php', 'label' => 'Employee List'],
                ['page' => 'insert1.php', 'href' => 'insert1.php', 'label' => 'Add Employee'],
            ], $current_page, null, 'indigo'); ?>
            <?php
            $pending_corrections = 0;
            if ($sidebar_role === 'admin' && isset($conn) && $conn) {
                $table_check = $conn->query("SHOW TABLES LIKE 'attendance_corrections'");
                if ($table_check && $table_check->num_rows > 0) {
                    $cr = $conn->query("SELECT COUNT(*) as cnt FROM attendance_corrections WHERE status = 'Pending'");
                    $pending_corrections = $cr ? (int)$cr->fetch_assoc()['cnt'] : 0;
                }
            }
            echo nav_section('Attendance', 'calendar-check', [
                ['page' => 'attendance.php', 'href' => 'attendance.php', 'label' => 'Monthly Attendance'],
                ['page' => 'dailyattendance.php', 'href' => 'dailyattendance.php', 'label' => 'Daily Attendance'],
                ['page' => 'monthly_attendance_report.php', 'href' => 'monthly_attendance_report.php', 'label' => 'Attendance Report'],
                ['page' => 'attendance_settings.php', 'href' => 'attendance_settings.php', 'label' => 'Settings'],
                ['page' => 'attendance_corrections.php', 'href' => 'attendance_corrections.php', 'label' => 'Corrections'],
                ['page' => 'attendance_management.php', 'href' => 'attendance_management.php', 'label' => 'Management'],
            ], $current_page, $pending_corrections, 'emerald'); ?>
            <?php echo nav_section('Leave', 'paper-plane', [
                ['page' => 'leaveApproval.php', 'href' => 'leaveApproval.php', 'label' => 'Leave Approvals'],
                ['page' => 'leavereport.php', 'href' => 'leavereport.php', 'label' => 'Leave Report'],
            ], $current_page, $pending_leaves, 'amber'); ?>
            <?php echo nav_section('Overtime', 'stopwatch', [
                // ['page' => 'overtime_dashboard.php', 'href' => 'overtime_dashboard.php', 'label' => 'Dashboard'],
                ['page' => 'overtimeApproval.php', 'href' => 'overtimeApproval.php', 'label' => 'OT Approvals'],
                ['page' => 'assign_overtime.php', 'href' => 'assign_overtime.php', 'label' => 'Assign OT'],
                ['page' => 'assignment_list.php', 'href' => 'assignment_list.php', 'label' => 'Assignments'],
                ['page' => 'overtimereport.php', 'href' => 'overtimereport.php', 'label' => 'OT Report'],

            ], $current_page, $pending_ot, 'orange'); ?>
            <?php echo nav_section('Payroll', 'money-bill-wave', [
                // ['page' => 'payroll_dashboard.php', 'href' => 'payroll_dashboard.php', 'label' => 'Dashboard'],
                ['page' => 'payroll.php', 'href' => 'payroll.php', 'label' => 'Generate Payroll'],
                // ['page' => 'payroll_list.php', 'href' => 'payroll_list.php', 'label' => 'Payroll List'],
                // ['page' => 'salary_structure.php', 'href' => 'salary_structure.php', 'label' => 'Salary Structure'],
                // ['page' => 'salaryreport.php', 'href' => 'salaryreport.php', 'label' => 'Salary Report'],
                ['page' => 'salary_slip.php', 'href' => 'salary_slip.php', 'label' => 'Salary Slips'],
                ['page' => 'bonous.php', 'href' => 'bonous.php', 'label' => 'Bonuses'],
                ['page' => 'deduction.php', 'href' => 'deduction.php', 'label' => 'Deductions'],
                ['page' => 'payroll_reports.php', 'href' => 'payroll_reports.php', 'label' => 'Reports'],
            ], $current_page, null, 'sky'); ?>

            <div class="nav-text px-3 pt-4 pb-1.5">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 tracking-wider uppercase">Organization</p>
            </div>
            <?php echo nav_item('department.php', 'Departments', 'building', $current_page, null, null, 'indigo'); ?>
            <?php echo nav_item('position.php', 'Positions', 'briefcase', $current_page, null, null, 'orange'); ?>
            <?php echo nav_item('holiday.php', 'Holidays', 'calendar-day', $current_page, null, null, 'cyan'); ?>
            <?php echo nav_item('policy.php', 'Company Policy', 'file-contract', $current_page, null, null, 'purple'); ?>

            <?php echo nav_section('Reports', 'chart-column', [
                ['page' => 'reports.php', 'href' => 'reports.php', 'label' => 'Annual Report'],
                ['page' => 'salaryreport.php', 'href' => 'salaryreport.php', 'label' => 'Salary Report'],
                ['page' => 'salary_slip.php', 'href' => 'salary_slip.php', 'label' => 'Salary Slips'],
                ['page' => 'leavereport.php', 'href' => 'leavereport.php', 'label' => 'Leave Report'],
                ['page' => 'overtimereport.php', 'href' => 'overtimereport.php', 'label' => 'OT Report'],
                ['page' => 'overtime_reports.php', 'href' => 'overtime_reports.php', 'label' => 'OT Reports'],
            ], $current_page, null, 'rose'); ?>



        <?php elseif ($sidebar_role === 'employee'): ?>

            <div class="nav-text px-3 pb-1.5 pt-1">
                <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 tracking-wider uppercase">Main Menu</p>
            </div>
            <?php echo nav_item('dashboard.php', 'Dashboard', 'house-chimney', $current_page, null, null, 'indigo'); ?>
            <?php echo nav_section('Attendance', 'calendar-check', [
                ['page' => 'attendance.php', 'href' => 'attendance.php', 'label' => 'Check In/Out'],
                ['page' => 'attendance_calendar.php', 'href' => 'attendance_calendar.php', 'label' => 'Calendar'],
                ['page' => 'attendanceall.php', 'href' => 'attendanceall.php', 'label' => 'My Records'],
                ['page' => 'attendance_overtime_overview.php', 'href' => 'attendance_overtime_overview.php', 'label' => 'Attendance & OT Overview'],
            ], $current_page, null, 'emerald'); ?>
            <?php echo nav_item('leaverequest.php', 'Leave Request', 'paper-plane', $current_page, null, null, 'amber'); ?>
            <?php echo nav_item('overtimerequest.php', 'Overtime Request', 'stopwatch', $current_page, null, null, 'orange'); ?>
            <?php
            // Show OT Approvals for approver-role employees
            if (isset($conn) && $conn && isset($_SESSION['employee_id'])) {
                $emp_role_stmt = $conn->prepare("SELECT role FROM employee WHERE id = ?");
                $emp_role_stmt->bind_param('i', $_SESSION['employee_id']);
                $emp_role_stmt->execute();
                $emp_role_row = $emp_role_stmt->get_result()->fetch_assoc();
                $emp_role_stmt->close();
                $emp_role = strtolower(trim($emp_role_row['role'] ?? ''));
                if (in_array($emp_role, ['admin', 'manager', 'supervisor', 'officer']) || $_SESSION['employee_id'] == 1) {
                    $pending_ot_q = $conn->query("SELECT COUNT(*) as cnt FROM overtime_requests WHERE approver_id = " . (int)$_SESSION['employee_id'] . " AND status = 'Pending'");
                    $pending_ot_count = $pending_ot_q ? (int)$pending_ot_q->fetch_assoc()['cnt'] : 0;
                    echo nav_item('overtime_approval.php', 'OT Approvals', 'user-check', $current_page, null, $pending_ot_count, 'teal');
                }
            }
            ?>
            <?php echo nav_section('Payroll', 'money-bill-wave', [
                ['page' => 'payroll.php', 'href' => 'payroll.php', 'label' => 'My Payroll'],
            ], $current_page, null, 'sky'); ?>
            <?php echo nav_item('company_policy.php', 'Company Policy', 'file-contract', $current_page, null, null, 'purple'); ?>

        <?php endif; ?>

    </nav>

    <!-- Collapse Toggle -->
    <div class="px-3 pb-3 pt-2 border-t border-slate-200 dark:border-white/[0.06]">
        <button class="sidebar-collapse-btn" onclick="toggleDesktopSidebar()" title="Toggle sidebar">
            <i class="fa-solid fa-chevron-left" id="sidebarToggleIcon"></i>
        </button>
    </div>
</aside>

<!-- Sidebar JS -->
<script>
    (function() {
        var sidebar = document.querySelector('aside');
        if (!sidebar) return;

        function applyCollapse(collapsed) {
            if (collapsed) {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
                var icon = document.getElementById('sidebarToggleIcon');
                if (icon) {
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                }
            } else {
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
                var icon = document.getElementById('sidebarToggleIcon');
                if (icon) {
                    icon.classList.remove('fa-chevron-right');
                    icon.classList.add('fa-chevron-left');
                }
            }
        }

        function isTablet() {
            return window.innerWidth >= 768 && window.innerWidth < 1024;
        }

        window.toggleDesktopSidebar = function() {
            var next = !sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebar-collapsed', next);
            applyCollapse(next);
        };

        var saved = localStorage.getItem('sidebar-collapsed') === 'true';
        applyCollapse(saved);

        // Tooltip on collapsed hover
        var tip = null,
            hideT = null;
        sidebar.addEventListener('mouseover', function(e) {
            if (!sidebar.classList.contains('collapsed')) return;
            var item = e.target.closest('.nav-item');
            if (!item) return;
            var txt = item.getAttribute('data-tooltip') || item.querySelector('.nav-text')?.textContent?.trim();
            if (!txt) return;
            clearTimeout(hideT);
            if (!tip) {
                tip = document.createElement('div');
                tip.className = 'sidebar-tooltip';
                document.body.appendChild(tip);
            }
            tip.textContent = txt;
            var r = item.getBoundingClientRect();
            tip.style.top = (r.top + r.height / 2 - 14) + 'px';
            tip.style.left = (r.right + 10) + 'px';
            requestAnimationFrame(function() {
                tip.classList.add('visible');
            });
        });
        sidebar.addEventListener('mouseout', function(e) {
            if (e.target.closest('.nav-item')) hideT = setTimeout(function() {
                if (tip) tip.classList.remove('visible');
            }, 80);
        });
    })();
</script>