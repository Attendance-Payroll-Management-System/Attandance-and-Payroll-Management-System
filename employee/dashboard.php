<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }
$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];
set_mmt_timezone();
$today = mmt_date();
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

// ── Employee Info ──
$emp_info = $conn->prepare("SELECT e.name, e.employee_code, e.email, e.phone, e.profile_photo, d.department_name, p.position_name FROM employee e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN positions p ON e.position_id = p.id WHERE e.id = ?");
$emp_info->bind_param("i", $employee_id);
$emp_info->execute();
$emp_data = $emp_info->get_result()->fetch_assoc();
$emp_info->close();
$emp_photo = $emp_data['profile_photo'] ?? '';
$emp_position = $emp_data['position_name'] ?? 'Employee';
$emp_department = $emp_data['department_name'] ?? '';
$emp_code = $emp_data['employee_code'] ?? '';

// ── Attendance ──
$att = $conn->prepare("SELECT COUNT(*) as total_days, SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days, SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days, SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days, SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as effective_present FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$att->bind_param("iss", $employee_id, $month_start, $month_end);
$att->execute();
$att_data = $att->get_result()->fetch_assoc();
$att->close();
$total_days = $att_data['total_days'] ?? 0;
$present_days = $att_data['present_days'] ?? 0;
$late_days = $att_data['late_days'] ?? 0;
$half_days = $att_data['half_days'] ?? 0;
$effective_present = $att_data['effective_present'] ?? 0;
$absent_days = max(0, $total_days - $present_days);
$attendance_rate = $total_days > 0 ? round(($effective_present / $total_days) * 100, 1) : 0;

// ── Leave ──
$leave = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected FROM leave_requests WHERE employee_id = ? AND created_at BETWEEN ? AND ?");
$leave->bind_param("iss", $employee_id, $month_start, $month_end);
$leave->execute();
$leave_data = $leave->get_result()->fetch_assoc();
$leave->close();

// ── Overtime ──
$ot = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as approved_hours, COUNT(*) as total_requests FROM overtime_requests WHERE employee_id = ? AND status = 'Approved' AND ot_date BETWEEN ? AND ?");
$ot->bind_param("iss", $employee_id, $month_start, $month_end);
$ot->execute();
$ot_data = $ot->get_result()->fetch_assoc();
$ot->close();
$ot_hours = $ot_data['approved_hours'] ?? 0;

// ── Pending Attendance Corrections ──
$corr_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM attendance_corrections WHERE employee_id = ? AND status = 'Pending'");
$corr_stmt->bind_param("i", $employee_id);
$corr_stmt->execute();
$pending_corrections = (int)$corr_stmt->get_result()->fetch_assoc()['cnt'];
$corr_stmt->close();

// ── Pending Counts ──
$pending_leaves = $leave_data['pending'] ?? 0;
$pending_ot_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM overtime_requests WHERE employee_id = ? AND status = 'Pending' AND ot_date BETWEEN ? AND ?");
$pending_ot_stmt->bind_param("iss", $employee_id, $month_start, $month_end);
$pending_ot_stmt->execute();
$pending_ot = $pending_ot_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$pending_ot_stmt->close();

// ── Payroll ──
$payroll = $conn->prepare("SELECT payroll_month, payroll_year, basic_salary, ot_amount, bonus_amount, deduction_amount, gross_salary, net_salary FROM payrolls WHERE employee_id = ? ORDER BY payroll_year DESC, payroll_month DESC LIMIT 1");
$payroll->bind_param("i", $employee_id);
$payroll->execute();
$payroll_data = $payroll->get_result()->fetch_assoc();
$payroll->close();

// ── Today's attendance ──
$today_att = $conn->prepare("SELECT check_in, check_out, status FROM attendance WHERE employee_id = ? AND attendance_date = ?");
$today_att->bind_param("is", $employee_id, $today);
$today_att->execute();
$today_status = $today_att->get_result()->fetch_assoc();
$today_att->close();
$has_checked_in = !empty($today_status['check_in']);
$has_checked_out = !empty($today_status['check_out']);

// ── Recent Attendance (last 7 records) ──
$recent_att_stmt = $conn->prepare("SELECT attendance_date, check_in, check_out, status, total_working_hours FROM attendance WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 7");
$recent_att_stmt->bind_param("i", $employee_id);
$recent_att_stmt->execute();
$recent_att = $recent_att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_att_stmt->close();

// ── Recent Leave Requests (last 5) ──
$recent_leave_stmt = $conn->prepare("SELECT leave_type, start_date, end_date, status, created_at FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_leave_stmt->bind_param("i", $employee_id);
$recent_leave_stmt->execute();
$recent_leaves = $recent_leave_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_leave_stmt->close();

// ── Recent Overtime Requests (last 5) ──
$recent_ot_stmt = $conn->prepare("SELECT ot_date, start_time, end_time, total_hours, status, created_at FROM overtime_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_ot_stmt->bind_param("i", $employee_id);
$recent_ot_stmt->execute();
$recent_overtime = $recent_ot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_ot_stmt->close();

// ── Calendar Data (attendance for current month) ──
$cal_stmt = $conn->prepare("SELECT attendance_date, status, check_in FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$cal_stmt->bind_param("iss", $employee_id, $month_start, $month_end);
$cal_stmt->execute();
$cal_result = $cal_stmt->get_result();
$calendar_data = [];
while ($row = $cal_result->fetch_assoc()) {
    $day = (int)date('d', strtotime($row['attendance_date']));
    $calendar_data[$day] = $row['status'];
}
$cal_stmt->close();

// ── Holidays this month ──
$hol_stmt = $conn->prepare("SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$hol_stmt->bind_param("ss", $month_start, $month_end);
$hol_stmt->execute();
$hol_result = $hol_stmt->get_result();
$holiday_data = [];
while ($row = $hol_result->fetch_assoc()) {
    $day = (int)date('d', strtotime($row['holiday_date']));
    $calendar_data[$day] = 'holiday';
    $holiday_data[$day] = $row['holiday_name'];
}
$hol_stmt->close();

// ── Greeting ──
$greeting = "Good Evening";
$hour = (int)mmt_date('H');
if ($hour < 12) $greeting = "Good Morning";
elseif ($hour < 17) $greeting = "Good Afternoon";

// ── Calendar Grid ──
$first_day = date('w', strtotime($month_start));
$days_in_month = date('t', strtotime($month_start));
$month_name = date('F Y', strtotime($month_start));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .dash-stat-card { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .dash-stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.08); }
        .dash-activity-item { transition: all 0.2s ease; }
        .dash-activity-item:hover { background: rgba(79,70,229,0.03); }
        .cal-day { width: 100%; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border-radius: 10px; font-size: 12px; font-weight: 600; transition: all 0.2s ease; cursor: default; }
        .cal-day.present { background: #DCFCE7; color: #16A34A; }
        .cal-day.late { background: #FEF3C7; color: #D97706; }
        .cal-day.absent { background: #FEE2E2; color: #DC2626; }
        .cal-day.leave { background: #E0E7FF; color: #4F46E5; }
        .cal-day.holiday { background: #F3E8FF; color: #7C3AED; }
        .cal-day.half_day { background: #CFFAFE; color: #0891B2; }
        .cal-day.today { box-shadow: inset 0 0 0 2px #4F46E5; }
        .dark .cal-day.present { background: rgba(34,197,94,0.15); color: #4ADE80; }
        .dark .cal-day.late { background: rgba(245,158,11,0.15); color: #FBBF24; }
        .dark .cal-day.absent { background: rgba(239,68,68,0.15); color: #F87171; }
        .dark .cal-day.leave { background: rgba(79,70,229,0.15); color: #818CF8; }
        .dark .cal-day.holiday { background: rgba(124,58,237,0.15); color: #A78BFA; }
        .dark .cal-day.half_day { background: rgba(6,182,212,0.15); color: #22D3EE; }
        .dark .cal-day.today { box-shadow: inset 0 0 0 2px #818CF8; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .status-dot.present-dot { background: #22C55E; }
        .status-dot.late-dot { background: #F59E0B; }
        .status-dot.absent-dot { background: #EF4444; }
        .status-dot.leave-dot { background: #6366F1; }
        .status-dot.half_dot { background: #06B6D4; }
    </style>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Dashboard"; $page_subtitle = "Welcome back, here's your overview"; include "../includes/topbar.php"; ?>
        <main class="flex-1 page-content w-full emp-page-transition">

            <!-- ═══ WELCOME CARD ═══ -->
            <section class="px-4 sm:px-6 lg:px-8 pt-6 pb-2 max-w-7xl mx-auto w-full">
                <div class="relative overflow-hidden bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-500 rounded-2xl p-6 lg:p-8">
                    <div class="absolute inset-0 opacity-10">
                        <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                        <div class="absolute bottom-0 left-0 w-48 h-48 bg-white rounded-full blur-3xl translate-y-1/2 -translate-x-1/4"></div>
                    </div>
                    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                        <div class="flex items-center gap-4">
                            <?php if (!empty($emp_photo)): ?>
                            <img src="../<?php echo htmlspecialchars($emp_photo); ?>" alt="" class="w-14 h-14 lg:w-16 lg:h-16 rounded-2xl object-cover border-2 border-white/30 shadow-lg shrink-0">
                            <?php else: ?>
                            <div class="w-14 h-14 lg:w-16 lg:h-16 rounded-2xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-white text-xl font-bold border-2 border-white/30 shadow-lg shrink-0">
                                <?php echo strtoupper(substr($employee_name, 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="text-sm text-white/70 font-medium"><?php echo $greeting; ?>,</p>
                                <h2 class="text-xl lg:text-2xl font-extrabold text-white tracking-tight"><?php echo htmlspecialchars($employee_name); ?></h2>
                                <p class="text-xs text-white/60 mt-0.5"><?php echo htmlspecialchars($emp_position); ?><?php if ($emp_department): ?> · <?php echo htmlspecialchars($emp_department); ?><?php endif; ?></p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <?php if ($has_checked_in && !$has_checked_out): ?>
                            <span class="inline-flex items-center gap-2 bg-emerald-500/20 text-emerald-100 rounded-full px-4 py-2 text-xs font-semibold"><span class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse"></span>Currently Working</span>
                            <?php elseif ($has_checked_out): ?>
                            <span class="inline-flex items-center gap-2 bg-white/15 text-white/90 rounded-full px-4 py-2 text-xs font-semibold"><i class="fa-solid fa-check-circle"></i>Day Complete</span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-2 bg-amber-500/20 text-amber-100 rounded-full px-4 py-2 text-xs font-semibold"><i class="fa-solid fa-clock"></i>Not Checked In</span>
                            <?php endif; ?>
                            <span class="inline-flex items-center gap-2 bg-white/15 text-white/90 rounded-full px-4 py-2 text-xs font-semibold"><i class="fa-solid fa-calendar-check"></i><?php echo $present_days; ?>/<?php echo $total_days; ?> Days</span>
                        </div>
                    </div>

                    <!-- Quick Stats Row inside Welcome -->
                    <div class="relative z-10 mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/10">
                            <p class="text-[10px] font-semibold text-white/50 uppercase tracking-wider">Present Days</p>
                            <p class="text-lg font-bold text-white mt-0.5"><?php echo $present_days; ?></p>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/10">
                            <p class="text-[10px] font-semibold text-white/50 uppercase tracking-wider">Leave Balance</p>
                            <p class="text-lg font-bold text-white mt-0.5"><?php echo $leave_data['pending'] ?? 0; ?> <span class="text-xs text-white/50 font-normal">pending</span></p>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/10">
                            <p class="text-[10px] font-semibold text-white/50 uppercase tracking-wider">OT Hours</p>
                            <p class="text-lg font-bold text-white mt-0.5"><?php echo number_format($ot_hours, 1); ?>h</p>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm rounded-xl px-4 py-3 border border-white/10">
                            <p class="text-[10px] font-semibold text-white/50 uppercase tracking-wider">Attendance</p>
                            <p class="text-lg font-bold text-white mt-0.5"><?php echo $attendance_rate; ?>%</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ STATISTICS CARDS ═══ -->
            <section class="px-4 sm:px-6 lg:px-8 py-4 max-w-7xl mx-auto w-full">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                    <!-- This Month Attendance -->
                    <div class="dash-stat-card bg-white dark:bg-[#1E293B] rounded-2xl p-5 border border-slate-100 dark:border-white/[0.06] shadow-sm">
                        <div class="flex items-start justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center text-blue-500 dark:text-blue-400"><i class="fa-solid fa-calendar-check text-lg"></i></div>
                            <span class="text-[10px] font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-500/10 px-2 py-0.5 rounded-full"><?php echo $attendance_rate; ?>%</span>
                        </div>
                        <div class="text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo $present_days; ?> <span class="text-sm font-medium text-slate-400 dark:text-slate-500">/ <?php echo $total_days; ?></span></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Attendance Days</p>
                        <div class="mt-3 h-1.5 bg-slate-100 dark:bg-white/[0.06] rounded-full overflow-hidden"><div class="h-full bg-blue-500 rounded-full" style="width: <?php echo $attendance_rate; ?>%"></div></div>
                    </div>

                    <!-- Total Leaves -->
                    <div class="dash-stat-card bg-white dark:bg-[#1E293B] rounded-2xl p-5 border border-slate-100 dark:border-white/[0.06] shadow-sm">
                        <div class="flex items-start justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-500/10 flex items-center justify-center text-amber-500 dark:text-amber-400"><i class="fa-solid fa-paper-plane text-lg"></i></div>
                            <?php if ($pending_leaves > 0): ?>
                            <span class="text-[10px] font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 px-2 py-0.5 rounded-full"><?php echo $pending_leaves; ?> pending</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo $leave_data['total'] ?? 0; ?></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Total Leave Requests</p>
                        <div class="mt-3 flex items-center gap-2 text-[10px] font-semibold">
                            <span class="text-emerald-500"><i class="fa-solid fa-check mr-0.5"></i><?php echo $leave_data['approved'] ?? 0; ?> approved</span>
                            <span class="text-amber-500"><i class="fa-solid fa-hourglass-half mr-0.5"></i><?php echo $pending_leaves; ?> pending</span>
                        </div>
                    </div>

                    <!-- Pending Requests -->
                    <div class="dash-stat-card bg-white dark:bg-[#1E293B] rounded-2xl p-5 border border-slate-100 dark:border-white/[0.06] shadow-sm">
                        <div class="flex items-start justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-500/10 flex items-center justify-center text-rose-500 dark:text-rose-400"><i class="fa-solid fa-bell text-lg"></i></div>
                            <?php $total_pending = $pending_leaves + $pending_ot; if ($total_pending > 0): ?>
                            <span class="text-[10px] font-bold text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-500/10 px-2 py-0.5 rounded-full"><?php echo $total_pending; ?> new</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo $total_pending; ?></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Pending Requests</p>
                        <div class="mt-3 flex items-center gap-2 text-[10px] font-semibold">
                            <span class="text-amber-500"><?php echo $pending_leaves; ?> leave</span>
                            <span class="text-orange-500"><?php echo $pending_ot; ?> OT</span>
                        </div>
                    </div>

                    <!-- Overtime Hours -->
                    <div class="dash-stat-card bg-white dark:bg-[#1E293B] rounded-2xl p-5 border border-slate-100 dark:border-white/[0.06] shadow-sm">
                        <div class="flex items-start justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-500/10 flex items-center justify-center text-purple-500 dark:text-purple-400"><i class="fa-solid fa-stopwatch text-lg"></i></div>
                            <?php if ($ot_hours > 0): ?>
                            <span class="text-[10px] font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-500/10 px-2 py-0.5 rounded-full"><?php echo $ot_data['total_requests'] ?? 0; ?> req</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo number_format($ot_hours, 1); ?> <span class="text-sm font-medium text-slate-400 dark:text-slate-500">hrs</span></div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Overtime Hours</p>
                        <div class="mt-3 h-1.5 bg-slate-100 dark:bg-white/[0.06] rounded-full overflow-hidden"><div class="h-full bg-purple-500 rounded-full" style="width: <?php echo min(100, ($ot_hours / 48) * 100); ?>%"></div></div>
                    </div>
                </div>
            </section>

            <!-- ═══ ATTENDANCE SUMMARY + CALENDAR ═══ -->
            <section class="px-4 sm:px-6 lg:px-8 pb-4 max-w-7xl mx-auto w-full">
                <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">

                    <!-- Attendance Summary (Left - wider) -->
                    <div class="xl:col-span-3 bg-white dark:bg-[#1E293B] rounded-2xl p-6 border border-slate-100 dark:border-white/[0.06] shadow-sm">
                        <div class="flex items-center justify-between mb-5">
                            <h3 class="font-bold text-slate-900 dark:text-white flex items-center gap-2"><i class="fa-solid fa-chart-simple text-blue-500 dark:text-blue-400"></i>Attendance Summary</h3>
                            <div class="flex items-center gap-2">
                                <?php if ($pending_corrections > 0): ?>
                                <a href="attendance.php?tab=corrections" class="text-xs font-semibold text-rose-500 dark:text-rose-400 hover:text-rose-600 dark:hover:text-rose-300 transition-colors flex items-center gap-1"><span class="w-1.5 h-1.5 bg-rose-500 rounded-full animate-pulse"></span><?php echo $pending_corrections; ?> correction<?php echo $pending_corrections > 1 ? 's' : ''; ?></a>
                                <?php endif; ?>
                                <a href="attendance_calendar.php" class="text-xs font-semibold text-indigo-500 dark:text-indigo-400 hover:text-indigo-600 dark:hover:text-indigo-300 transition-colors"><i class="fa-regular fa-calendar mr-1"></i>Calendar</a>
                                <a href="attendance_summary.php" class="text-xs font-semibold text-blue-500 dark:text-blue-400 hover:text-blue-600 dark:hover:text-blue-300 transition-colors">View All <i class="fa-solid fa-arrow-right ml-1"></i></a>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mb-5">
                            <div class="flex items-center justify-between text-sm mb-2">
                                <span class="text-slate-500 dark:text-slate-400">Monthly Progress</span>
                                <span class="font-bold text-slate-900 dark:text-white"><?php echo $present_days; ?> / <?php echo $total_days; ?> days</span>
                            </div>
                            <div class="h-3 bg-slate-100 dark:bg-white/[0.06] rounded-full overflow-hidden">
                                <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 transition-all duration-700" style="width: <?php echo $attendance_rate; ?>%"></div>
                            </div>
                        </div>

                        <!-- Breakdown Grid -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div class="bg-blue-50 dark:bg-blue-500/10 rounded-xl p-4 text-center border border-blue-100 dark:border-blue-500/10">
                                <div class="status-dot present-dot mx-auto mb-2"></div>
                                <span class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $present_days; ?></span>
                                <p class="text-[10px] text-blue-500/70 dark:text-blue-400/60 font-semibold uppercase tracking-wider mt-1">Present</p>
                            </div>
                            <div class="bg-amber-50 dark:bg-amber-500/10 rounded-xl p-4 text-center border border-amber-100 dark:border-amber-500/10">
                                <div class="status-dot late-dot mx-auto mb-2"></div>
                                <span class="text-2xl font-bold text-amber-600 dark:text-amber-400"><?php echo $late_days; ?></span>
                                <p class="text-[10px] text-amber-500/70 dark:text-amber-400/60 font-semibold uppercase tracking-wider mt-1">Late</p>
                            </div>
                            <div class="bg-rose-50 dark:bg-rose-500/10 rounded-xl p-4 text-center border border-rose-100 dark:border-rose-500/10">
                                <div class="status-dot absent-dot mx-auto mb-2"></div>
                                <span class="text-2xl font-bold text-rose-600 dark:text-rose-400"><?php echo $absent_days; ?></span>
                                <p class="text-[10px] text-rose-500/70 dark:text-rose-400/60 font-semibold uppercase tracking-wider mt-1">Absent</p>
                            </div>
                            <div class="bg-indigo-50 dark:bg-indigo-500/10 rounded-xl p-4 text-center border border-indigo-100 dark:border-indigo-500/10">
                                <div class="status-dot leave-dot mx-auto mb-2"></div>
                                <span class="text-2xl font-bold text-indigo-600 dark:text-indigo-400"><?php echo $leave_data['total'] ?? 0; ?></span>
                                <p class="text-[10px] text-indigo-500/70 dark:text-indigo-400/60 font-semibold uppercase tracking-wider mt-1">Leave</p>
                            </div>
                        </div>

                        <!-- Payroll Quick View -->
                        <?php if ($payroll_data): ?>
                        <div class="mt-5 bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl p-4 text-white relative overflow-hidden">
                            <div class="absolute top-0 right-0 w-20 h-20 bg-white/10 rounded-full blur-xl -translate-y-1/2 translate-x-1/2"></div>
                            <div class="relative flex items-center justify-between">
                                <div>
                                    <p class="text-xs text-white/70 font-medium">Net Pay (Latest)</p>
                                    <p class="text-xl font-bold mt-0.5">$<?php echo number_format($payroll_data['net_salary'], 2); ?></p>
                                </div>
                                <a href="payroll.php" class="bg-white/20 hover:bg-white/30 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors">View Slip <i class="fa-solid fa-arrow-right ml-1"></i></a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Calendar Widget (Right - narrower) -->
                    <div class="xl:col-span-2 bg-white dark:bg-[#1E293B] rounded-2xl p-6 border border-slate-100 dark:border-white/[0.06] shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-bold text-slate-900 dark:text-white flex items-center gap-2"><i class="fa-solid fa-calendar text-indigo-500 dark:text-indigo-400"></i><?php echo $month_name; ?></h3>
                            <a href="attendance_calendar.php" class="text-xs font-semibold text-indigo-500 dark:text-indigo-400 hover:text-indigo-600 dark:hover:text-indigo-300 transition-colors">Full View <i class="fa-solid fa-arrow-right ml-1"></i></a>
                        </div>

                        <!-- Day Headers -->
                        <div class="grid grid-cols-7 gap-1 mb-2">
                            <?php foreach (['Su','Mo','Tu','We','Th','Fr','Sa'] as $day): ?>
                            <div class="text-center text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase"><?php echo $day; ?></div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Calendar Grid -->
                        <div class="grid grid-cols-7 gap-1">
                            <?php for ($i = 0; $i < $first_day; $i++): ?>
                            <div></div>
                            <?php endfor; ?>
                            <?php for ($day = 1; $day <= $days_in_month; $day++):
                                $status = $calendar_data[$day] ?? '';
                                $is_today = ($day == (int)mmt_date('d'));
                                $classes = 'cal-day';
                                if ($is_today) $classes .= ' today';
                                if ($status) $classes .= ' ' . $status;
                            ?>
                            <div class="<?php echo $classes; ?>" title="<?php echo $status ? ucfirst(str_replace('_', ' ', $status)) : ''; ?>"><?php echo $day; ?></div>
                            <?php endfor; ?>
                        </div>

                        <!-- Calendar Legend -->
                        <div class="mt-4 flex flex-wrap gap-3 text-[10px] font-semibold text-slate-500 dark:text-slate-400">
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-green-200 dark:bg-green-500/30"></span>Present</span>
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-amber-200 dark:bg-amber-500/30"></span>Late</span>
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-red-200 dark:bg-red-500/30"></span>Absent</span>
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-indigo-200 dark:bg-indigo-500/30"></span>Leave</span>
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded bg-purple-200 dark:bg-purple-500/30"></span>Holiday</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ RECENT ACTIVITIES ═══ -->
            <section class="px-4 sm:px-6 lg:px-8 pb-8 max-w-7xl mx-auto w-full">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- Recent Attendance -->
                    <div class="bg-white dark:bg-[#1E293B] rounded-2xl border border-slate-100 dark:border-white/[0.06] shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 dark:border-white/[0.06] flex items-center justify-between">
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm flex items-center gap-2"><i class="fa-solid fa-clock-rotate-left text-blue-500 dark:text-blue-400"></i>Recent Attendance</h3>
                            <a href="attendanceall.php" class="text-[10px] font-semibold text-blue-500 dark:text-blue-400 hover:text-blue-600 dark:hover:text-blue-300">View All</a>
                        </div>
                        <div class="divide-y divide-slate-50 dark:divide-white/[0.04]">
                            <?php if (empty($recent_att)): ?>
                            <div class="p-6 text-center text-xs text-slate-400 dark:text-slate-500">No records yet.</div>
                            <?php else: foreach ($recent_att as $att_record):
                                $att_date = date('M d', strtotime($att_record['attendance_date']));
                                $att_day = date('D', strtotime($att_record['attendance_date']));
                                $att_status = $att_record['status'];
                                $dot_class = match($att_status) { 'present' => 'present-dot', 'late' => 'late-dot', 'half_day' => 'half_dot', default => 'absent-dot' };
                            ?>
                            <div class="dash-activity-item flex items-center gap-3 px-5 py-3">
                                <div class="text-center shrink-0 w-10">
                                    <p class="text-xs font-bold text-slate-900 dark:text-white"><?php echo $att_date; ?></p>
                                    <p class="text-[10px] text-slate-400 dark:text-slate-500"><?php echo $att_day; ?></p>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span class="status-dot <?php echo $dot_class; ?>"></span>
                                        <span class="text-xs font-semibold text-slate-700 dark:text-slate-300 capitalize"><?php echo str_replace('_', ' ', $att_status); ?></span>
                                    </div>
                                    <?php if ($att_record['check_in']): ?>
                                    <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5">
                                        <?php echo date('h:i A', strtotime($att_record['check_in'])); ?>
                                        <?php if ($att_record['check_out']): ?> — <?php echo date('h:i A', strtotime($att_record['check_out'])); ?><?php endif; ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($att_record['total_working_hours']): ?>
                                <span class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 shrink-0"><?php echo number_format($att_record['total_working_hours'], 1); ?>h</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <!-- Recent Leave Requests -->
                    <div class="bg-white dark:bg-[#1E293B] rounded-2xl border border-slate-100 dark:border-white/[0.06] shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 dark:border-white/[0.06] flex items-center justify-between">
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm flex items-center gap-2"><i class="fa-solid fa-paper-plane text-amber-500 dark:text-amber-400"></i>Leave Requests</h3>
                            <a href="leaverequest.php" class="text-[10px] font-semibold text-blue-500 dark:text-blue-400 hover:text-blue-600 dark:hover:text-blue-300">View All</a>
                        </div>
                        <div class="divide-y divide-slate-50 dark:divide-white/[0.04]">
                            <?php if (empty($recent_leaves)): ?>
                            <div class="p-6 text-center text-xs text-slate-400 dark:text-slate-500">No leave requests yet.</div>
                            <?php else: foreach ($recent_leaves as $lr):
                                $lr_status_class = match($lr['status']) {
                                    'Approved' => 'bg-emerald-50 text-emerald-600 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20',
                                    'Rejected' => 'bg-red-50 text-red-600 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20',
                                    default => 'bg-amber-50 text-amber-600 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20'
                                };
                            ?>
                            <div class="dash-activity-item flex items-center gap-3 px-5 py-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($lr['leave_type']); ?></p>
                                    <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5"><?php echo date('M d', strtotime($lr['start_date'])); ?> — <?php echo date('M d', strtotime($lr['end_date'])); ?></p>
                                </div>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border <?php echo $lr_status_class; ?>"><?php echo $lr['status']; ?></span>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <!-- Recent Overtime -->
                    <div class="bg-white dark:bg-[#1E293B] rounded-2xl border border-slate-100 dark:border-white/[0.06] shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 dark:border-white/[0.06] flex items-center justify-between">
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm flex items-center gap-2"><i class="fa-solid fa-stopwatch text-purple-500 dark:text-purple-400"></i>Overtime Requests</h3>
                            <a href="overtimerequest.php" class="text-[10px] font-semibold text-blue-500 dark:text-blue-400 hover:text-blue-600 dark:hover:text-blue-300">View All</a>
                        </div>
                        <div class="divide-y divide-slate-50 dark:divide-white/[0.04]">
                            <?php if (empty($recent_overtime)): ?>
                            <div class="p-6 text-center text-xs text-slate-400 dark:text-slate-500">No overtime requests yet.</div>
                            <?php else: foreach ($recent_overtime as $otr):
                                $otr_status_class = match($otr['status']) {
                                    'Approved' => 'bg-emerald-50 text-emerald-600 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20',
                                    'Rejected' => 'bg-red-50 text-red-600 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20',
                                    default => 'bg-amber-50 text-amber-600 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20'
                                };
                            ?>
                            <div class="dash-activity-item flex items-center gap-3 px-5 py-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-300"><?php echo date('M d', strtotime($otr['ot_date'])); ?></p>
                                    <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5"><?php echo date('h:i A', strtotime($otr['start_time'])); ?> — <?php echo date('h:i A', strtotime($otr['end_time'])); ?></p>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border <?php echo $otr_status_class; ?>"><?php echo $otr['status']; ?></span>
                                    <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5"><?php echo number_format($otr['total_hours'], 1); ?>h</p>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </section>

        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>
