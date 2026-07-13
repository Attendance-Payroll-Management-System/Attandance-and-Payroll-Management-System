<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }
$employee_id = $_SESSION['employee_id'];
set_mmt_timezone();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Company Policies</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Company Policies"; $page_subtitle = "Review company rules, guidelines, and employee responsibilities"; include "../includes/topbar.php"; ?>
        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full">

            <!-- Quick Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 animate-fade-in-up">
                <div class="glass-strong rounded-2xl p-4 text-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center text-lg mx-auto mb-2">
                        <i class="fa-solid fa-building"></i>
                    </div>
                    <div class="text-lg font-bold text-slate-900 dark:text-white">8</div>
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-500">Sections</span>
                </div>
                <div class="glass-strong rounded-2xl p-4 text-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center text-lg mx-auto mb-2">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="text-lg font-bold text-slate-900 dark:text-white">8:00-5:00</div>
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-500">Working Hours</span>
                </div>
                <div class="glass-strong rounded-2xl p-4 text-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center text-lg mx-auto mb-2">
                        <i class="fa-solid fa-calendar-days"></i>
                    </div>
                    <div class="text-lg font-bold text-slate-900 dark:text-white">Mon-Sat</div>
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-500">Work Days</span>
                </div>
                <div class="glass-strong rounded-2xl p-4 text-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-500/20 to-pink-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center text-lg mx-auto mb-2">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div class="text-lg font-bold text-slate-900 dark:text-white">Zero</div>
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-zinc-500">Tolerance</span>
                </div>
            </div>

            <!-- ═══ General Company Rules ═══ -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-1" x-data="{ open: true }">
                <div class="px-5 sm:px-6 py-4 border-b border-slate-200/60 dark:border-white/[0.06] bg-gradient-to-r from-blue-500/10 to-indigo-500/5 cursor-pointer" @click="open = !open">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-slate-900 dark:text-white flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center">
                                <i class="fa-solid fa-building text-sm"></i>
                            </div>
                            General Company Rules
                        </h4>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </div>
                </div>
                <div x-show="open" x-transition class="p-5 sm:p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-id-card text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Employee Identification</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">All employees must wear their company ID badge at all times while on company premises. The badge must be visible and presented upon request.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-clock text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Punctuality</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees are expected to arrive at least 5 minutes before their scheduled shift. Consistent tardiness will result in disciplinary action.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-shirt text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Dress Code</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Business casual attire is required. Employees must maintain a professional appearance. Casual wear is permitted only on designated casual Fridays.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-ban text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Prohibited Activities</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Smoking, consuming alcohol, and gambling on company premises are strictly prohibited. Violations may result in immediate termination.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Attendance Policy ═══ -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-2" x-data="{ open: true }">
                <div class="px-5 sm:px-6 py-4 border-b border-slate-200/60 dark:border-white/[0.06] bg-gradient-to-r from-emerald-500/10 to-teal-500/5 cursor-pointer" @click="open = !open">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-slate-900 dark:text-white flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center">
                                <i class="fa-solid fa-calendar-check text-sm"></i>
                            </div>
                            Attendance Policy
                        </h4>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </div>
                </div>
                <div x-show="open" x-transition class="p-5 sm:p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-right-to-bracket text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Check-In / Check-Out</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees must check in and out using the biometric system or mobile app. Failure to check out will be treated as absence for the remaining hours.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-hourglass-half text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Late Arrivals</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Arriving more than 5 minutes after the scheduled start time is considered late. 3 late arrivals in a month will result in a written warning.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-user-xmark text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Absenteeism</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Unexcused absence for 3 or more consecutive days without prior approval may be considered job abandonment and result in termination.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-mobile-screen text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Remote Check-In</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">For field work or remote days, employees must check in via the mobile app at the scheduled start time and submit a daily activity report.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Leave Policy ═══ -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-3" x-data="{ open: false }">
                <div class="px-5 sm:px-6 py-4 border-b border-slate-200/60 dark:border-white/[0.06] bg-gradient-to-r from-sky-500/10 to-cyan-500/5 cursor-pointer" @click="open = !open">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-slate-900 dark:text-white flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-sky-500/20 text-sky-500 dark:text-sky-400 flex items-center justify-center">
                                <i class="fa-solid fa-plane-departure text-sm"></i>
                            </div>
                            Leave Policy
                        </h4>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </div>
                </div>
                <div x-show="open" x-transition class="p-5 sm:p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-sky-500/20 text-sky-500 dark:text-sky-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-calendar-day text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Annual Leave</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees are entitled to 15 days of paid annual leave per year. Leave must be requested at least 3 days in advance and approved by the supervisor.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-stethoscope text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Sick Leave</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Up to 10 days of paid sick leave per year. A medical certificate is required for absences exceeding 2 consecutive days.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-purple-500/20 text-purple-500 dark:text-purple-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-baby text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Maternity / Paternity Leave</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Maternity leave of 90 days (paid) and paternity leave of 7 days (paid) as per company policy and labor law requirements.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-ring text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Personal Leave</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Up to 5 days of unpaid personal leave per year for personal matters. Requires supervisor approval at least 1 week in advance.</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-200 dark:border-amber-500/20">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-triangle-exclamation text-amber-500 dark:text-amber-400 mt-0.5"></i>
                            <div>
                                <h5 class="text-sm font-bold text-amber-700 dark:text-amber-400 mb-1">Important Note</h5>
                                <p class="text-xs text-amber-600 dark:text-zinc-400 leading-relaxed">Leave requests must be submitted through the system at least 3 days in advance. Emergency leave may be reported via phone call to the immediate supervisor, followed by system submission within 24 hours.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Overtime Policy ═══ -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-4" x-data="{ open: false }">
                <div class="px-5 sm:px-6 py-4 border-b border-slate-200/60 dark:border-white/[0.06] bg-gradient-to-r from-amber-500/10 to-orange-500/5 cursor-pointer" @click="open = !open">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-slate-900 dark:text-white flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center">
                                <i class="fa-solid fa-clock text-sm"></i>
                            </div>
                            Overtime Policy
                        </h4>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </div>
                </div>
                <div x-show="open" x-transition class="p-5 sm:p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-check-circle text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Prior Approval Required</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">All overtime must be pre-approved by the direct supervisor or department head. Unauthorized overtime will not be compensated.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-calculator text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Overtime Rate</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Overtime is compensated at 1.5x the regular hourly rate for weekdays and 2x for weekends and holidays, as per labor law.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-hourglass-end text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Maximum Hours</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Maximum overtime is capped at 48 hours per month. Exceeding this limit requires special approval from HR and management.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-file-lines text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Documentation</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Overtime must be recorded in the system within the same day. Claims submitted after 48 hours will not be processed.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Payroll Policy ═══ -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-5" x-data="{ open: false }">
                <div class="px-5 sm:px-6 py-4 border-b border-slate-200/60 dark:border-white/[0.06] bg-gradient-to-r from-cyan-500/10 to-blue-500/5 cursor-pointer" @click="open = !open">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-slate-900 dark:text-white flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-cyan-500/20 text-cyan-500 dark:text-cyan-400 flex items-center justify-center">
                                <i class="fa-solid fa-money-bill-wave text-sm"></i>
                            </div>
                            Payroll Policy
                        </h4>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </div>
                </div>
                <div x-show="open" x-transition class="p-5 sm:p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-cyan-500/20 text-cyan-500 dark:text-cyan-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-calendar-check text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Payment Schedule</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Salaries are paid on the last working day of each month via direct bank transfer. Payslips are available through the employee portal.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-receipt text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Deductions</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Standard deductions include tax, social security, and any loan repayments. Unauthorized absences and late arrivals may result in salary deductions.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-gift text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Bonuses</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Performance bonuses are awarded quarterly based on individual and company performance. Attendance and conduct are key factors in bonus evaluation.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-purple-500/20 text-purple-500 dark:text-purple-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-file-invoice text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Salary Slip Access</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees can download their salary slips from the Payroll section. Disputes must be raised within 5 business days of payment.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Office Working Hours ═══ -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-6" x-data="{ open: false }">
                <div class="px-5 sm:px-6 py-4 border-b border-slate-200/60 dark:border-white/[0.06] bg-gradient-to-r from-indigo-500/10 to-purple-500/5 cursor-pointer" @click="open = !open">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-slate-900 dark:text-white flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-indigo-500/20 text-indigo-500 dark:text-indigo-400 flex items-center justify-center">
                                <i class="fa-solid fa-business-time text-sm"></i>
                            </div>
                            Office Working Hours
                        </h4>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </div>
                </div>
                <div x-show="open" x-transition class="p-5 sm:p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-indigo-500/20 text-indigo-500 dark:text-indigo-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-sun text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Standard Hours</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Office hours are 8:00 AM to 5:00 PM, Monday through Saturday. A 1-hour lunch break is provided between 12:00 PM and 1:00 PM.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-mug-hot text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Break Time</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees are entitled to two 15-minute rest breaks in addition to the lunch break. Breaks should not exceed the allotted time.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-vest text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Grace Period</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">A 5-minute grace period is allowed for clock-in. After the grace period, the system automatically marks the employee as late.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-moon text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Night Shift</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Night shift employees (10:00 PM - 6:00 AM) receive an additional night differential as per company policy and labor regulations.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Holidays ═══ -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-7" x-data="{ open: false }">
                <div class="px-5 sm:px-6 py-4 border-b border-slate-200/60 dark:border-white/[0.06] bg-gradient-to-r from-rose-500/10 to-pink-500/5 cursor-pointer" @click="open = !open">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-slate-900 dark:text-white flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center">
                                <i class="fa-solid fa-star text-sm"></i>
                            </div>
                            Company Holidays
                        </h4>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </div>
                </div>
                <div x-show="open" x-transition class="p-5 sm:p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-star text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Public Holidays</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">The company observes all official public holidays as declared by the government. Employees receive full pay for these days.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-purple-500/20 text-purple-500 dark:text-purple-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-building-columns text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Company-Specific Holidays</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">In addition to public holidays, the company observes founding day and year-end closing. These dates are communicated in advance.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-briefcase text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Working on Holidays</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees required to work on holidays receive 2x pay plus a replacement day off. Approval from department head is mandatory.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-bell text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Holiday Schedule</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">The annual holiday calendar is published at the start of each year. Any changes are communicated via the company notification system.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Employee Responsibilities ═══ -->
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-8" x-data="{ open: false }">
                <div class="px-5 sm:px-6 py-4 border-b border-slate-200/60 dark:border-white/[0.06] bg-gradient-to-r from-teal-500/10 to-emerald-500/5 cursor-pointer" @click="open = !open">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-slate-900 dark:text-white flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-teal-500/20 text-teal-500 dark:text-teal-400 flex items-center justify-center">
                                <i class="fa-solid fa-user-check text-sm"></i>
                            </div>
                            Employee Responsibilities
                        </h4>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 dark:text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </div>
                </div>
                <div x-show="open" x-transition class="p-5 sm:p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-teal-500/20 text-teal-500 dark:text-teal-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-handshake text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Professional Conduct</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees must maintain professional behavior, treat colleagues with respect, and contribute to a positive work environment at all times.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-lock text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Confidentiality</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees must protect company data, client information, and trade secrets. Unauthorized disclosure is grounds for immediate termination.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-chart-line text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Performance</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees are expected to meet performance targets, attend regular reviews, and continuously develop their skills and knowledge.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-4 bg-slate-50 dark:bg-white/[0.03] rounded-xl border border-slate-200/60 dark:border-white/[0.06]">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center shrink-0 mt-0.5">
                                <i class="fa-solid fa-shield text-sm"></i>
                            </div>
                            <div>
                                <h5 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Safety Compliance</h5>
                                <p class="text-xs text-slate-600 dark:text-zinc-400 leading-relaxed">Employees must follow all safety protocols, report hazards immediately, and complete mandatory safety training as scheduled.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact HR -->
            <div class="glass-strong rounded-2xl p-6 text-center animate-fade-in-up stagger-1">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center text-xl mx-auto mb-3">
                    <i class="fa-solid fa-headset"></i>
                </div>
                <h4 class="text-sm font-bold text-slate-900 dark:text-white mb-1">Questions About Policies?</h4>
                <p class="text-xs text-slate-500 dark:text-zinc-400 mb-3">Contact the HR Department for clarification on any company policy.</p>
                <a href="profile.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-xs font-semibold px-5 py-2.5 rounded-xl hover:shadow-lg hover:shadow-blue-500/25 transition-all duration-200">
                    <i class="fa-solid fa-user"></i> Contact HR
                </a>
            </div>

        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>