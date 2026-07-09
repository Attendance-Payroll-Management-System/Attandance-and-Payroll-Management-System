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
$att = $conn->prepare("SELECT COUNT(*) as total_days, SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days, SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days, SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as effective_present FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$att->bind_param("iss", $employee_id, $month_start, $month_end);
$att->execute();
$att_data = $att->get_result()->fetch_assoc();
$att->close();
$total_days = $att_data['total_days'] ?? 0;
$present_days = $att_data['present_days'] ?? 0;
$late_days = $att_data['late_days'] ?? 0;
$effective_present = $att_data['effective_present'] ?? 0;
$attendance_rate = $total_days > 0 ? round(($effective_present / $total_days) * 100, 1) : 0;
$leave = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending FROM leave_requests WHERE employee_id = ? AND created_at BETWEEN ? AND ?");
$leave->bind_param("iss", $employee_id, $month_start, $month_end);
$leave->execute();
$leave_data = $leave->get_result()->fetch_assoc();
$leave->close();
$ot = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as approved_hours, COUNT(*) as total_requests FROM overtime_requests WHERE employee_id = ? AND status = 'Approved' AND ot_date BETWEEN ? AND ?");
$ot->bind_param("iss", $employee_id, $month_start, $month_end);
$ot->execute();
$ot_data = $ot->get_result()->fetch_assoc();
$ot->close();
$ot_hours = $ot_data['approved_hours'] ?? 0;
$bonus = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as total_count FROM bonuses WHERE employee_id = ?");
$bonus->bind_param("i", $employee_id);
$bonus->execute();
$bonus_data = $bonus->get_result()->fetch_assoc();
$bonus->close();
$deduction = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as total_count FROM deductions WHERE employee_id = ?");
$deduction->bind_param("i", $employee_id);
$deduction->execute();
$deduction_data = $deduction->get_result()->fetch_assoc();
$deduction->close();
$payroll = $conn->prepare("SELECT payroll_month, payroll_year, basic_salary, ot_amount, bonus_amount, deduction_amount, gross_salary, net_salary FROM payrolls WHERE employee_id = ? ORDER BY payroll_year DESC, payroll_month DESC LIMIT 1");
$payroll->bind_param("i", $employee_id);
$payroll->execute();
$payroll_data = $payroll->get_result()->fetch_assoc();
$payroll->close();
$annual = $conn->prepare("SELECT payroll_year, total_salary, total_bonus, total_deduction, total_ot, net_annual_salary FROM annual_payrolls WHERE employee_id = ? ORDER BY payroll_year DESC LIMIT 1");
$annual->bind_param("i", $employee_id);
$annual->execute();
$annual_data = $annual->get_result()->fetch_assoc();
$annual->close();
$today_att = $conn->prepare("SELECT check_in, check_out FROM attendance WHERE employee_id = ? AND attendance_date = ?");
$today_att->bind_param("is", $employee_id, $today);
$today_att->execute();
$today_status = $today_att->get_result()->fetch_assoc();
$today_att->close();
$greeting = "Good Evening";
$hour = (int)date('H');
if ($hour < 12) $greeting = "Good Morning";
elseif ($hour < 17) $greeting = "Good Afternoon";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper min-h-screen flex flex-col">
        <?php $page_title = "Dashboard"; $page_subtitle = $greeting . ', ' . htmlspecialchars($employee_name) . ' · ' . date('l, F j, Y'); include "../includes/topbar.php"; ?>
        <main class="p-6 lg:p-8 space-y-8 flex-1 page-content w-full">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="group glass-strong rounded-2xl hover:-translate-y-1 transition-all duration-300 p-5 card-hover animate-fade-in-up stagger-1">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-sky-500/20 to-cyan-500/20 text-sky-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-calendar-check"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Attendance</span>
                                <div class="text-2xl font-bold text-gradient mt-0.5"><?php echo $present_days; ?><span class="text-sm font-medium text-zinc-500">/<?php echo $total_days; ?></span></div>
                            </div>
                        </div>
                        <span class="badge <?php echo $attendance_rate >= 80 ? 'badge-emerald' : ($attendance_rate >= 50 ? 'badge-amber' : 'badge-rose'); ?>"><?php echo $attendance_rate; ?>%</span>
                    </div>
                    <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: <?php echo $attendance_rate; ?>%"></div></div>
                    <div class="mt-2 text-xs text-zinc-500"><span class="notif-dot warning"></span><?php echo $late_days; ?> late this month</div>
                </div>
                <div class="group glass-strong rounded-2xl hover:-translate-y-1 transition-all duration-300 p-5 card-hover animate-fade-in-up stagger-2">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-plane-departure"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Leave</span>
                                <div class="text-2xl font-bold text-gradient-emerald mt-0.5"><?php echo $leave_data['total'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs inline-flex items-center gap-2">
                        <span class="badge badge-emerald"><?php echo $leave_data['approved'] ?? 0; ?> approved</span>
                        <span class="badge badge-amber"><?php echo $leave_data['pending'] ?? 0; ?> pending</span>
                    </div>
                </div>
                <div class="group glass-strong rounded-2xl hover:-translate-y-1 transition-all duration-300 p-5 card-hover animate-fade-in-up stagger-3">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-clock"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Overtime</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?php echo number_format($ot_hours, 1); ?> <span class="text-sm font-medium text-zinc-500">hrs</span></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-zinc-500"><i class="fa-solid fa-list-check mr-1 text-purple-400"></i><?php echo $ot_data['total_requests'] ?? 0; ?> approved requests</div>
                </div>
                <div class="group glass-strong rounded-2xl hover:-translate-y-1 transition-all duration-300 p-5 card-hover animate-fade-in-up stagger-4">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/20 text-amber-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-gift"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Bonuses</span>
                                <div class="text-2xl font-bold text-gradient-amber mt-0.5">$<?php echo number_format($bonus_data['total_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-zinc-500"><i class="fa-solid fa-layer-group mr-1 text-amber-400"></i><?php echo $bonus_data['total_count'] ?? 0; ?> records</div>
                </div>
                <div class="group glass-strong rounded-2xl hover:-translate-y-1 transition-all duration-300 p-5 card-hover animate-fade-in-up stagger-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-500/20 to-red-500/20 text-rose-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-minus-circle"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Deductions</span>
                                <div class="text-2xl font-bold text-white mt-0.5">$<?php echo number_format($deduction_data['total_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-zinc-500"><i class="fa-solid fa-layer-group mr-1 text-rose-400"></i><?php echo $deduction_data['total_count'] ?? 0; ?> records</div>
                </div>
                <div class="group glass-strong rounded-2xl hover:-translate-y-1 transition-all duration-300 p-5 card-hover animate-fade-in-up stagger-6">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-500/20 text-cyan-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Payroll</span>
                                <div class="text-2xl font-bold text-gradient-sky mt-0.5"><?php echo $payroll_data ? '$' . number_format($payroll_data['net_salary'], 2) : '--'; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-zinc-500"><?php echo $payroll_data ? 'Month '.$payroll_data['payroll_month'].' / '.$payroll_data['payroll_year'] : 'No payroll yet'; ?></div>
                </div>
                <div class="group glass-strong rounded-2xl hover:-translate-y-1 transition-all duration-300 p-5 card-hover animate-fade-in-up stagger-7">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500/20 to-blue-500/20 text-indigo-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform duration-300"><i class="fa-solid fa-chart-line"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Annual Payroll</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?php echo $annual_data ? '$' . number_format($annual_data['net_annual_salary'], 2) : '--'; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-zinc-500"><?php echo $annual_data ? 'Year '.$annual_data['payroll_year'] : 'No annual data yet'; ?></div>
                </div>
                <div class="group bg-gradient-to-br from-blue-600 via-indigo-600 to-sky-600 rounded-2xl shadow-xl shadow-blue-600/10 hover:shadow-2xl hover:shadow-blue-500/20 hover:-translate-y-1 transition-all duration-300 p-5 text-white animate-fade-in-up stagger-8 card-inner-glow">
                    <div class="flex items-start justify-between relative z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-bolt"></i></div>
                            <div><span class="text-xs font-semibold uppercase tracking-wider text-white/70">Quick Actions</span></div>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-2 relative z-10">
                        <a href="attendance.php" class="flex-1 bg-white/15 hover:bg-white/25 text-white text-xs font-semibold py-2.5 rounded-xl border border-white/10 transition-all duration-200 text-center backdrop-blur-sm hover:scale-105 active:scale-95"><i class="fa-solid fa-fingerprint mr-1"></i> Attendance</a>
                        <a href="leaverequest.php" class="flex-1 bg-white/15 hover:bg-white/25 text-white text-xs font-semibold py-2.5 rounded-xl border border-white/10 transition-all duration-200 text-center backdrop-blur-sm hover:scale-105 active:scale-95"><i class="fa-solid fa-envelope mr-1"></i> Leave</a>
                        <a href="overtimerequest.php" class="flex-1 bg-white/15 hover:bg-white/25 text-white text-xs font-semibold py-2.5 rounded-xl border border-white/10 transition-all duration-200 text-center backdrop-blur-sm hover:scale-105 active:scale-95"><i class="fa-solid fa-clock mr-1"></i> OT</a>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-1">
                    <div class="flex items-center justify-between border-b border-white/[0.06] pb-4 mb-4">
                        <h3 class="font-bold text-white"><i class="fa-solid fa-chart-simple text-sky-400 mr-2"></i>Attendance Overview</h3>
                        <a href="attendanceall.php" class="text-xs font-semibold text-sky-400 hover:text-sky-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>View All</a>
                    </div>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-400">Present Days</span>
                            <span class="font-bold text-white"><?php echo $present_days; ?> / <?php echo $total_days; ?> days</span>
                        </div>
                        <div class="progress-bar"><div class="progress-bar-fill" style="width: <?php echo $attendance_rate; ?>%"></div></div>
                        <div class="grid grid-cols-3 gap-3 pt-2">
                            <div class="bg-blue-500/10 rounded-xl p-3 text-center ring-1 ring-blue-500/10 glow-navy">
                                <span class="text-lg font-bold text-sky-400"><?php echo $present_days; ?></span>
                                <p class="text-[10px] text-sky-400/60 font-medium uppercase tracking-wider">Present</p>
                            </div>
                            <div class="bg-amber-500/10 rounded-xl p-3 text-center ring-1 ring-amber-500/10 glow-amber">
                                <span class="text-lg font-bold text-amber-400"><?php echo $late_days; ?></span>
                                <p class="text-[10px] text-amber-400/60 font-medium uppercase tracking-wider">Late</p>
                            </div>
                            <div class="bg-rose-500/10 rounded-xl p-3 text-center ring-1 ring-rose-500/10 glow-rose">
                                <span class="text-lg font-bold text-rose-400"><?php echo max(0, $total_days - $present_days); ?></span>
                                <p class="text-[10px] text-rose-400/60 font-medium uppercase tracking-wider">Absent</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-2">
                    <div class="flex items-center justify-between border-b border-white/[0.06] pb-4 mb-4">
                        <h3 class="font-bold text-white"><i class="fa-solid fa-wallet text-emerald-400 mr-2"></i>Payroll Summary</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-white/[0.04] rounded-xl p-4 ring-1 ring-white/[0.06]">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Basic Salary</span>
                            <div class="text-lg font-bold text-gradient-emerald mt-1">$<?php echo $payroll_data ? number_format($payroll_data['basic_salary'], 2) : '--'; ?></div>
                        </div>
                        <div class="bg-white/[0.04] rounded-xl p-4 ring-1 ring-white/[0.06]">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">OT Amount</span>
                            <div class="text-lg font-bold text-gradient-sky mt-1">$<?php echo $payroll_data && $payroll_data['ot_amount'] ? number_format($payroll_data['ot_amount'], 2) : '0.00'; ?></div>
                        </div>
                        <div class="bg-white/[0.04] rounded-xl p-4 ring-1 ring-white/[0.06]">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Bonuses</span>
                            <div class="text-lg font-bold text-gradient-amber mt-1">$<?php echo $payroll_data && $payroll_data['bonus_amount'] ? number_format($payroll_data['bonus_amount'], 2) : '0.00'; ?></div>
                        </div>
                        <div class="bg-white/[0.04] rounded-xl p-4 ring-1 ring-white/[0.06]">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Deductions</span>
                            <div class="text-lg font-bold text-white mt-1">$<?php echo $payroll_data && $payroll_data['deduction_amount'] ? number_format($payroll_data['deduction_amount'], 2) : '0.00'; ?></div>
                        </div>
                    </div>
                    <div class="mt-4 bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl p-4 text-white">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold">Net Pay (Latest)</span>
                            <span class="text-2xl font-bold">$<?php echo $payroll_data ? number_format($payroll_data['net_salary'], 2) : '--'; ?></span>
                        </div>
                        <div class="flex items-center justify-between mt-1">
                            <span class="text-xs text-white/70">Gross</span>
                            <span class="text-xs text-white/70">$<?php echo $payroll_data ? number_format($payroll_data['gross_salary'], 2) : '--'; ?></span>
                        </div>
                    </div>
                    <?php if ($annual_data): ?>
                    <div class="mt-4 border-t border-white/[0.06] pt-4">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-zinc-500">Annual Net (<?php echo $annual_data['payroll_year']; ?>)</span>
                            <span class="text-lg font-bold text-sky-400">$<?php echo number_format($annual_data['net_annual_salary'], 2); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>