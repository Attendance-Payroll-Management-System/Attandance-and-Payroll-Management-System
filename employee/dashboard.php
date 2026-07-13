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

// Attendance
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

// Leave
$leave = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending FROM leave_requests WHERE employee_id = ? AND created_at BETWEEN ? AND ?");
$leave->bind_param("iss", $employee_id, $month_start, $month_end);
$leave->execute();
$leave_data = $leave->get_result()->fetch_assoc();
$leave->close();

// Overtime
$ot = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as approved_hours, COUNT(*) as total_requests FROM overtime_requests WHERE employee_id = ? AND status = 'Approved' AND ot_date BETWEEN ? AND ?");
$ot->bind_param("iss", $employee_id, $month_start, $month_end);
$ot->execute();
$ot_data = $ot->get_result()->fetch_assoc();
$ot->close();
$ot_hours = $ot_data['approved_hours'] ?? 0;

// Payroll
$payroll = $conn->prepare("SELECT payroll_month, payroll_year, basic_salary, ot_amount, bonus_amount, deduction_amount, gross_salary, net_salary FROM payrolls WHERE employee_id = ? ORDER BY payroll_year DESC, payroll_month DESC LIMIT 1");
$payroll->bind_param("i", $employee_id);
$payroll->execute();
$payroll_data = $payroll->get_result()->fetch_assoc();
$payroll->close();

// Today's attendance
$today_att = $conn->prepare("SELECT check_in, check_out FROM attendance WHERE employee_id = ? AND attendance_date = ?");
$today_att->bind_param("is", $employee_id, $today);
$today_att->execute();
$today_status = $today_att->get_result()->fetch_assoc();
$today_att->close();

$has_checked_in = !empty($today_status['check_in']);
$has_checked_out = !empty($today_status['check_out']);

// Greeting
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
    <style>
        /* Hero floating animation */
        @keyframes heroFloat { 0%,100%{ transform: translateY(0px) rotate(0deg); } 50%{ transform: translateY(-12px) rotate(2deg); } }
        @keyframes heroFloat2 { 0%,100%{ transform: translateY(0px) rotate(0deg); } 50%{ transform: translateY(-8px) rotate(-2deg); } }
        @keyframes heroPulse { 0%,100%{ opacity: 0.6; transform: scale(1); } 50%{ opacity: 1; transform: scale(1.05); } }
        @keyframes heroSpin { from{ transform: rotate(0deg); } to{ transform: rotate(360deg); } }
        @keyframes slideInLeft { from{ opacity:0; transform: translateX(-30px); } to{ opacity:1; transform: translateX(0); } }
        @keyframes slideInRight { from{ opacity:0; transform: translateX(30px); } to{ opacity:1; transform: translateX(0); } }
        @keyframes scaleIn { from{ opacity:0; transform: scale(0.9); } to{ opacity:1; transform: scale(1); } }
        @keyframes countUp { from{ opacity:0; transform: translateY(10px); } to{ opacity:1; transform: translateY(0); } }
        .hero-float { animation: heroFloat 6s ease-in-out infinite; }
        .hero-float-2 { animation: heroFloat2 5s ease-in-out infinite 0.5s; }
        .hero-pulse { animation: heroPulse 4s ease-in-out infinite; }
        .hero-spin { animation: heroSpin 20s linear infinite; }
        .slide-in-left { animation: slideInLeft 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .slide-in-right { animation: slideInRight 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards 0.2s; opacity: 0; }
        .scale-in { animation: scaleIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .count-up { animation: countUp 0.5s ease forwards; }

        /* Quick action hover glow */
        .action-card { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .action-card:hover { transform: translateY(-6px) scale(1.02); }
        .action-card:active { transform: translateY(-2px) scale(0.98); }

        /* Summary card shimmer */
        .summary-card { transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
        .summary-card:hover { transform: translateY(-4px); }

        /* Clock styling */
        .clock-display { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Dashboard"; $page_subtitle = ""; include "../includes/topbar.php"; ?>
        <main class="flex-1 page-content w-full emp-page-transition">

            <!-- ═══ HERO SECTION ═══ -->
            <section class="relative overflow-hidden">
                <!-- Background decoration -->
                <div class="absolute inset-0 overflow-hidden pointer-events-none">
                    <div class="absolute -top-40 -right-40 w-80 h-80 bg-gradient-to-br from-blue-500/10 to-indigo-500/10 rounded-full blur-3xl hero-pulse"></div>
                    <div class="absolute -bottom-20 -left-20 w-60 h-60 bg-gradient-to-br from-cyan-500/10 to-teal-500/10 rounded-full blur-3xl hero-pulse" style="animation-delay: 2s;"></div>
                    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-gradient-to-br from-indigo-500/5 to-purple-500/5 rounded-full blur-3xl hero-spin"></div>
                </div>

                <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 lg:py-12">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12 items-center">

                        <!-- Left Side — Illustration -->
                        <div class="slide-in-left order-2 lg:order-1 flex justify-center">
                            <div class="relative w-full max-w-md">
                                <!-- Main Illustration SVG -->
                                <div class="hero-float">
                                    <svg viewBox="0 0 500 420" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto drop-shadow-2xl">
                                        <!-- Background circle -->
                                        <circle cx="250" cy="210" r="180" fill="url(#heroGrad1)" opacity="0.15"/>
                                        <circle cx="250" cy="210" r="140" fill="url(#heroGrad2)" opacity="0.1"/>

                                        <!-- Desk -->
                                        <rect x="80" y="280" width="340" height="12" rx="6" fill="url(#deskGrad)"/>
                                        <rect x="100" y="292" width="8" height="60" rx="4" fill="#1E3A8A" opacity="0.6"/>
                                        <rect x="392" y="292" width="8" height="60" rx="4" fill="#1E3A8A" opacity="0.6"/>

                                        <!-- Monitor -->
                                        <rect x="150" y="160" width="200" height="120" rx="12" fill="#0F172A"/>
                                        <rect x="156" y="166" width="188" height="100" rx="8" fill="url(#screenGrad)"/>
                                        <!-- Screen content lines -->
                                        <rect x="170" y="182" width="80" height="6" rx="3" fill="#4F46E5" opacity="0.7"/>
                                        <rect x="170" y="196" width="120" height="4" rx="2" fill="#64748B" opacity="0.4"/>
                                        <rect x="170" y="206" width="100" height="4" rx="2" fill="#64748B" opacity="0.3"/>
                                        <rect x="170" y="220" width="60" height="20" rx="6" fill="#10B981" opacity="0.8"/>
                                        <rect x="240" y="220" width="60" height="20" rx="6" fill="#4F46E5" opacity="0.6"/>
                                        <!-- Monitor stand -->
                                        <rect x="230" y="280" width="40" height="8" rx="2" fill="#1E3A8A" opacity="0.5"/>
                                        <rect x="220" y="275" width="60" height="8" rx="4" fill="#1E3A8A" opacity="0.7"/>

                                        <!-- Person sitting -->
                                        <!-- Head -->
                                        <circle cx="250" cy="120" r="30" fill="url(#skinGrad)"/>
                                        <!-- Hair -->
                                        <path d="M220 115 C220 90 240 80 250 80 C260 80 280 90 280 115 C280 105 270 95 250 95 C230 95 220 105 220 115Z" fill="#1E293B"/>
                                        <!-- Eyes -->
                                        <circle cx="240" cy="118" r="3" fill="#1E293B"/>
                                        <circle cx="260" cy="118" r="3" fill="#1E293B"/>
                                        <!-- Smile -->
                                        <path d="M242 128 Q250 135 258 128" stroke="#1E293B" stroke-width="2" fill="none" stroke-linecap="round"/>
                                        <!-- Body (shirt) -->
                                        <path d="M225 148 L220 220 L280 220 L275 148 Q250 155 225 148Z" fill="url(#shirtGrad)"/>
                                        <!-- Arms -->
                                        <path d="M225 160 L190 200 L195 205 L230 170Z" fill="url(#shirtGrad)"/>
                                        <path d="M275 160 L310 200 L305 205 L270 170Z" fill="url(#shirtGrad)"/>
                                        <!-- Hands on desk -->
                                        <ellipse cx="192" cy="208" rx="10" ry="6" fill="url(#skinGrad)"/>
                                        <ellipse cx="308" cy="208" rx="10" ry="6" fill="url(#skinGrad)"/>

                                        <!-- Coffee mug -->
                                        <rect x="340" y="255" width="24" height="25" rx="4" fill="#F59E0B" opacity="0.9"/>
                                        <rect x="364" y="262" width="8" height="12" rx="4" stroke="#F59E0B" stroke-width="2" fill="none" opacity="0.7"/>
                                        <!-- Steam -->
                                        <path d="M348 250 Q352 240 348 230" stroke="#94A3B8" stroke-width="1.5" fill="none" opacity="0.5" class="hero-float-2"/>
                                        <path d="M356 248 Q360 238 356 228" stroke="#94A3B8" stroke-width="1.5" fill="none" opacity="0.4" class="hero-float-2" style="animation-delay:0.3s"/>

                                        <!-- Plant -->
                                        <rect x="90" y="255" width="20" height="25" rx="4" fill="#059669" opacity="0.8"/>
                                        <circle cx="100" cy="248" r="14" fill="#10B981" opacity="0.7"/>
                                        <circle cx="92" cy="242" r="8" fill="#34D399" opacity="0.6"/>
                                        <circle cx="108" cy="244" r="7" fill="#6EE7B7" opacity="0.5"/>

                                        <!-- Floating elements -->
                                        <g class="hero-float-2" style="animation-delay:1s">
                                            <circle cx="400" cy="120" r="18" fill="#4F46E5" opacity="0.15"/>
                                            <text x="400" y="126" text-anchor="middle" fill="#4F46E5" font-size="16">📊</text>
                                        </g>
                                        <g class="hero-float" style="animation-delay:0.5s">
                                            <circle cx="100" cy="100" r="16" fill="#10B981" opacity="0.15"/>
                                            <text x="100" y="106" text-anchor="middle" fill="#10B981" font-size="14">✅</text>
                                        </g>
                                        <g class="hero-float-2" style="animation-delay:1.5s">
                                            <circle cx="420" cy="220" r="14" fill="#F59E0B" opacity="0.15"/>
                                            <text x="420" y="226" text-anchor="middle" fill="#F59E0B" font-size="12">💰</text>
                                        </g>

                                        <defs>
                                            <linearGradient id="heroGrad1" x1="0" y1="0" x2="500" y2="420"><stop stop-color="#4F46E5"/><stop offset="1" stop-color="#06B6D4"/></linearGradient>
                                            <linearGradient id="heroGrad2" x1="500" y1="0" x2="0" y2="420"><stop stop-color="#10B981"/><stop offset="1" stop-color="#3B82F6"/></linearGradient>
                                            <linearGradient id="deskGrad" x1="80" y1="280" x2="420" y2="292"><stop stop-color="#1E3A8A"/><stop offset="1" stop-color="#3B82F6"/></linearGradient>
                                            <linearGradient id="screenGrad" x1="156" y1="166" x2="344" y2="266"><stop stop-color="#0F172A"/><stop offset="1" stop-color="#1E293B"/></linearGradient>
                                            <linearGradient id="skinGrad" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#FCD9B6"/><stop offset="1" stop-color="#F5C69A"/></linearGradient>
                                            <linearGradient id="shirtGrad" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#4F46E5"/><stop offset="1" stop-color="#3730A3"/></linearGradient>
                                        </defs>
                                    </svg>
                                </div>

                                <!-- Decorative badges floating around illustration -->
                                <div class="absolute top-4 right-0 hero-float" style="animation-delay: 0.3s;">
                                    <div class="glass-strong rounded-2xl px-3 py-2 shadow-lg flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center text-white text-xs"><i class="fa-solid fa-check"></i></div>
                                        <div><p class="text-[10px] font-bold text-white">Checked In</p><p class="text-[9px] text-zinc-400"><?php echo $has_checked_in ? date('h:i A', strtotime($today_status['check_in'])) : 'Not yet'; ?></p></div>
                                    </div>
                                </div>
                                <div class="absolute bottom-8 left-0 hero-float-2" style="animation-delay: 0.8s;">
                                    <div class="glass-strong rounded-2xl px-3 py-2 shadow-lg flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white text-xs"><i class="fa-solid fa-fire"></i></div>
                                        <div><p class="text-[10px] font-bold text-white"><?php echo $attendance_rate; ?>%</p><p class="text-[9px] text-zinc-400">Attendance</p></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Side — Welcome Content -->
                        <div class="slide-in-right order-1 lg:order-2 space-y-6">
                            <div>
                                <p class="text-sm font-semibold text-blue-500 dark:text-blue-400 tracking-wider uppercase mb-2"><?php echo $greeting; ?></p>
                                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight">
                                    <span class="text-gradient">Welcome Back!</span>
                                </h1>
                                <h2 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white mt-1">
                                    <?php echo htmlspecialchars($employee_name); ?>
                                </h2>
                            </div>
                            <p class="text-sm sm:text-base text-slate-500 dark:text-zinc-400 leading-relaxed max-w-lg">
                                Manage your attendance, leave requests, overtime, and company activities in one place.
                            </p>

                            <!-- Date & Time -->
                            <div class="flex flex-wrap items-center gap-4">
                                <div class="glass-strong rounded-xl px-4 py-2.5 flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center"><i class="fa-solid fa-calendar-day text-sm"></i></div>
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-zinc-500">Today</p>
                                        <p class="text-xs font-bold text-slate-900 dark:text-white clock-display" id="currentDate"><?php echo date('l, M j, Y'); ?></p>
                                    </div>
                                </div>
                                <div class="glass-strong rounded-xl px-4 py-2.5 flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center"><i class="fa-solid fa-clock text-sm"></i></div>
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400 dark:text-zinc-500">Time</p>
                                        <p class="text-xs font-bold text-slate-900 dark:text-white clock-display" id="currentTime"><?php echo date('h:i:s A'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Motivational Quote -->
                            <div class="glass-strong rounded-2xl p-4 border-l-4 border-gradient-to-b from-indigo-500 to-cyan-500 relative overflow-hidden">
                                <div class="absolute top-0 right-0 w-20 h-20 bg-gradient-to-br from-indigo-500/10 to-cyan-500/10 rounded-full blur-2xl"></div>
                                <div class="relative flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-cyan-500 flex items-center justify-center text-white text-sm shrink-0 mt-0.5"><i class="fa-solid fa-quote-left"></i></div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 dark:text-zinc-300 italic">Your dedication drives our success.</p>
                                        <p class="text-[10px] text-slate-400 dark:text-zinc-500 mt-1">— HNIN AKARI NWE Team</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Status -->
                            <div class="flex flex-wrap gap-3">
                                <?php if ($has_checked_in && !$has_checked_out): ?>
                                <div class="inline-flex items-center gap-2 bg-emerald-500/10 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 rounded-full px-4 py-2 text-xs font-semibold">
                                    <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                                    Currently Working
                                </div>
                                <?php elseif ($has_checked_out): ?>
                                <div class="inline-flex items-center gap-2 bg-blue-500/10 dark:bg-blue-500/15 text-blue-600 dark:text-blue-400 rounded-full px-4 py-2 text-xs font-semibold">
                                    <i class="fa-solid fa-check-circle"></i>
                                    Day Complete
                                </div>
                                <?php else: ?>
                                <div class="inline-flex items-center gap-2 bg-amber-500/10 dark:bg-amber-500/15 text-amber-600 dark:text-amber-400 rounded-full px-4 py-2 text-xs font-semibold">
                                    <i class="fa-solid fa-clock"></i>
                                    Not Checked In Yet
                                </div>
                                <?php endif; ?>

                                <div class="inline-flex items-center gap-2 bg-slate-100 dark:bg-white/5 text-slate-600 dark:text-zinc-400 rounded-full px-4 py-2 text-xs font-semibold">
                                    <i class="fa-solid fa-calendar-check"></i>
                                    <?php echo $present_days; ?>/<?php echo $total_days; ?> Days Present
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ QUICK ACTION CARDS ═══ -->
            <section class="px-4 sm:px-6 lg:px-8 pb-6 max-w-7xl mx-auto w-full emp-section-reveal">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white"><i class="fa-solid fa-bolt text-amber-400 mr-2"></i>Quick Actions</h3>
                </div>
                <div class="grid grid-cols-3 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
                    <!-- Check In / Out -->
                    <a href="attendance.php" class="action-card group glass-strong rounded-2xl p-4 sm:p-5 text-center relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-br from-emerald-500/5 to-teal-500/5 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                        <div class="relative">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 group-hover:shadow-lg group-hover:shadow-emerald-500/20 transition-all duration-300">
                                <i class="fa-solid fa-fingerprint"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-700 dark:text-zinc-300 mt-3 block">Check In/Out</span>
                        </div>
                    </a>

                    <!-- My Attendance -->
                    <a href="attendanceall.php" class="action-card group glass-strong rounded-2xl p-4 sm:p-5 text-center relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-indigo-500/5 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                        <div class="relative">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 group-hover:shadow-lg group-hover:shadow-blue-500/20 transition-all duration-300">
                                <i class="fa-solid fa-folder-open"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-700 dark:text-zinc-300 mt-3 block">My Records</span>
                        </div>
                    </a>

                    <!-- Leave Request -->
                    <a href="leaverequest.php" class="action-card group glass-strong rounded-2xl p-4 sm:p-5 text-center relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-br from-sky-500/5 to-cyan-500/5 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                        <div class="relative">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-sky-500/20 to-cyan-500/20 text-sky-500 dark:text-sky-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 group-hover:shadow-lg group-hover:shadow-sky-500/20 transition-all duration-300">
                                <i class="fa-solid fa-paper-plane"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-700 dark:text-zinc-300 mt-3 block">Leave</span>
                        </div>
                    </a>

                    <!-- Overtime -->
                    <a href="overtimerequest.php" class="action-card group glass-strong rounded-2xl p-4 sm:p-5 text-center relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-br from-amber-500/5 to-orange-500/5 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                        <div class="relative">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500/20 to-orange-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 group-hover:shadow-lg group-hover:shadow-amber-500/20 transition-all duration-300">
                                <i class="fa-solid fa-stopwatch"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-700 dark:text-zinc-300 mt-3 block">Overtime</span>
                        </div>
                    </a>

                    <!-- Company Policy -->
                    <a href="company_policy.php" class="action-card group glass-strong rounded-2xl p-4 sm:p-5 text-center relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-br from-purple-500/5 to-pink-500/5 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                        <div class="relative">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-500 dark:text-purple-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 group-hover:shadow-lg group-hover:shadow-purple-500/20 transition-all duration-300">
                                <i class="fa-solid fa-file-contract"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-700 dark:text-zinc-300 mt-3 block">Policy</span>
                        </div>
                    </a>

                    <!-- Notifications -->
                    <a href="#notifications" class="action-card group glass-strong rounded-2xl p-4 sm:p-5 text-center relative overflow-hidden" onclick="document.querySelector('[x-data] button[aria-label]')?.click(); return false;">
                        <div class="absolute inset-0 bg-gradient-to-br from-rose-500/5 to-pink-500/5 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                        <div class="relative">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-rose-500/20 to-pink-500/20 text-rose-500 dark:text-rose-400 flex items-center justify-center text-xl mx-auto group-hover:scale-110 group-hover:shadow-lg group-hover:shadow-rose-500/20 transition-all duration-300">
                                <i class="fa-solid fa-bell"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-700 dark:text-zinc-300 mt-3 block">Alerts</span>
                        </div>
                    </a>
                </div>
            </section>

            <!-- ═══ DASHBOARD SUMMARY CARDS ═══ -->
            <section class="px-4 sm:px-6 lg:px-8 pb-6 max-w-7xl mx-auto w-full emp-section-reveal">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white"><i class="fa-solid fa-chart-bar text-blue-400 mr-2"></i>This Month's Summary</h3>
                    <a href="attendance_summary.php" class="text-xs font-semibold text-blue-500 dark:text-blue-400 hover:text-blue-600 dark:hover:text-blue-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>View All</a>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                    <!-- Present Days -->
                    <div class="summary-card glass-strong rounded-2xl p-5 relative overflow-hidden group">
                        <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-br from-blue-500/10 to-indigo-500/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2 group-hover:scale-150 transition-transform duration-500"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center text-lg"><i class="fa-solid fa-user-check"></i></div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-blue-500 dark:text-blue-400 bg-blue-500/10 px-2 py-0.5 rounded-full"><?php echo $attendance_rate; ?>%</span>
                            </div>
                            <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white count-up"><?php echo $present_days; ?></div>
                            <p class="text-xs font-semibold text-slate-400 dark:text-zinc-500 mt-1">Present Days</p>
                            <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: <?php echo $attendance_rate; ?>%"></div></div>
                        </div>
                    </div>

                    <!-- Late Days -->
                    <div class="summary-card glass-strong rounded-2xl p-5 relative overflow-hidden group">
                        <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-br from-amber-500/10 to-orange-500/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2 group-hover:scale-150 transition-transform duration-500"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/20 text-amber-500 dark:text-amber-400 flex items-center justify-center text-lg"><i class="fa-solid fa-clock"></i></div>
                                <?php if ($late_days > 0): ?>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-amber-500 dark:text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded-full"><?php echo $late_days; ?>x</span>
                                <?php else: ?>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-500 dark:text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-full">Perfect</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white count-up"><?php echo $late_days; ?></div>
                            <p class="text-xs font-semibold text-slate-400 dark:text-zinc-500 mt-1">Late Days</p>
                            <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: <?php echo $total_days > 0 ? min(100, ($late_days / $total_days) * 100) : 0; ?>%; background: linear-gradient(135deg, #F59E0B, #F97316);"></div></div>
                        </div>
                    </div>

                    <!-- Leave Days -->
                    <div class="summary-card glass-strong rounded-2xl p-5 relative overflow-hidden group">
                        <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-br from-emerald-500/10 to-teal-500/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2 group-hover:scale-150 transition-transform duration-500"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center text-lg"><i class="fa-solid fa-plane-departure"></i></div>
                                <?php if (($leave_data['pending'] ?? 0) > 0): ?>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-amber-500 dark:text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded-full"><?php echo $leave_data['pending']; ?> pending</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white count-up"><?php echo $leave_data['total'] ?? 0; ?></div>
                            <p class="text-xs font-semibold text-slate-400 dark:text-zinc-500 mt-1">Leave Days</p>
                            <div class="mt-3 flex items-center gap-2">
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-500 dark:text-emerald-400"><i class="fa-solid fa-check"></i><?php echo $leave_data['approved'] ?? 0; ?> ok</span>
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-amber-500 dark:text-amber-400"><i class="fa-solid fa-hourglass-half"></i><?php echo $leave_data['pending'] ?? 0; ?> wait</span>
                            </div>
                        </div>
                    </div>

                    <!-- Overtime Hours -->
                    <div class="summary-card glass-strong rounded-2xl p-5 relative overflow-hidden group">
                        <div class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-br from-purple-500/10 to-pink-500/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2 group-hover:scale-150 transition-transform duration-500"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-500 dark:text-purple-400 flex items-center justify-center text-lg"><i class="fa-solid fa-stopwatch"></i></div>
                                <?php if ($ot_hours > 0): ?>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-purple-500 dark:text-purple-400 bg-purple-500/10 px-2 py-0.5 rounded-full"><?php echo $ot_data['total_requests'] ?? 0; ?> req</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white count-up"><?php echo number_format($ot_hours, 1); ?></div>
                            <p class="text-xs font-semibold text-slate-400 dark:text-zinc-500 mt-1">OT Hours</p>
                            <div class="mt-3 progress-bar"><div class="progress-bar-fill" style="width: <?php echo min(100, ($ot_hours / 48) * 100); ?>%; background: linear-gradient(135deg, #7C3AED, #EC4899);"></div></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ ATTENDANCE + PAYROLL OVERVIEW ═══ -->
            <section class="px-4 sm:px-6 lg:px-8 pb-8 max-w-7xl mx-auto w-full emp-section-reveal">
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <!-- Attendance Overview -->
                    <div class="glass-strong rounded-2xl p-6 animate-fade-in-up">
                        <div class="flex items-center justify-between border-b border-slate-200/60 dark:border-white/[0.06] pb-4 mb-5">
                            <h3 class="font-bold text-slate-900 dark:text-white flex items-center gap-2"><i class="fa-solid fa-chart-simple text-blue-500 dark:text-blue-400"></i>Attendance Overview</h3>
                            <a href="attendance_summary.php" class="text-xs font-semibold text-blue-500 dark:text-blue-400 hover:text-blue-600 dark:hover:text-blue-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>Full Summary</a>
                        </div>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-slate-500 dark:text-zinc-400">Monthly Progress</span>
                                <span class="font-bold text-slate-900 dark:text-white"><?php echo $present_days; ?> / <?php echo $total_days; ?> days</span>
                            </div>
                            <div class="progress-bar h-2.5"><div class="progress-bar-fill" style="width: <?php echo $attendance_rate; ?>%"></div></div>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2">
                                <div class="bg-blue-500/10 dark:bg-blue-500/15 rounded-xl p-3 text-center ring-1 ring-blue-500/10">
                                    <span class="text-xl font-bold text-blue-600 dark:text-blue-400"><?php echo $present_days; ?></span>
                                    <p class="text-[10px] text-blue-500/60 dark:text-blue-400/60 font-semibold uppercase tracking-wider mt-0.5">Present</p>
                                </div>
                                <div class="bg-amber-500/10 dark:bg-amber-500/15 rounded-xl p-3 text-center ring-1 ring-amber-500/10">
                                    <span class="text-xl font-bold text-amber-600 dark:text-amber-400"><?php echo $late_days; ?></span>
                                    <p class="text-[10px] text-amber-500/60 dark:text-amber-400/60 font-semibold uppercase tracking-wider mt-0.5">Late</p>
                                </div>
                                <div class="bg-teal-500/10 dark:bg-teal-500/15 rounded-xl p-3 text-center ring-1 ring-teal-500/10">
                                    <span class="text-xl font-bold text-teal-600 dark:text-teal-400"><?php echo $half_days; ?></span>
                                    <p class="text-[10px] text-teal-500/60 dark:text-teal-400/60 font-semibold uppercase tracking-wider mt-0.5">Half</p>
                                </div>
                                <div class="bg-rose-500/10 dark:bg-rose-500/15 rounded-xl p-3 text-center ring-1 ring-rose-500/10">
                                    <span class="text-xl font-bold text-rose-600 dark:text-rose-400"><?php echo $absent_days; ?></span>
                                    <p class="text-[10px] text-rose-500/60 dark:text-rose-400/60 font-semibold uppercase tracking-wider mt-0.5">Absent</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payroll Summary -->
                    <div class="glass-strong rounded-2xl p-6 animate-fade-in-up stagger-2">
                        <div class="flex items-center justify-between border-b border-slate-200/60 dark:border-white/[0.06] pb-4 mb-5">
                            <h3 class="font-bold text-slate-900 dark:text-white flex items-center gap-2"><i class="fa-solid fa-wallet text-emerald-500 dark:text-emerald-400"></i>Payroll Summary</h3>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-slate-50 dark:bg-white/[0.04] rounded-xl p-4 ring-1 ring-slate-200/60 dark:ring-white/[0.06]">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">Basic Salary</span>
                                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400 mt-1">$<?php echo $payroll_data ? number_format($payroll_data['basic_salary'], 2) : '--'; ?></div>
                            </div>
                            <div class="bg-slate-50 dark:bg-white/[0.04] rounded-xl p-4 ring-1 ring-slate-200/60 dark:ring-white/[0.06]">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">OT Amount</span>
                                <div class="text-lg font-bold text-sky-600 dark:text-sky-400 mt-1">$<?php echo $payroll_data && $payroll_data['ot_amount'] ? number_format($payroll_data['ot_amount'], 2) : '0.00'; ?></div>
                            </div>
                            <div class="bg-slate-50 dark:bg-white/[0.04] rounded-xl p-4 ring-1 ring-slate-200/60 dark:ring-white/[0.06]">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">Bonuses</span>
                                <div class="text-lg font-bold text-amber-600 dark:text-amber-400 mt-1">$<?php echo $payroll_data && $payroll_data['bonus_amount'] ? number_format($payroll_data['bonus_amount'], 2) : '0.00'; ?></div>
                            </div>
                            <div class="bg-slate-50 dark:bg-white/[0.04] rounded-xl p-4 ring-1 ring-slate-200/60 dark:ring-white/[0.06]">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">Deductions</span>
                                <div class="text-lg font-bold text-rose-600 dark:text-rose-400 mt-1">$<?php echo $payroll_data && $payroll_data['deduction_amount'] ? number_format($payroll_data['deduction_amount'], 2) : '0.00'; ?></div>
                            </div>
                        </div>
                        <div class="mt-4 bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl p-4 text-white relative overflow-hidden">
                            <div class="absolute top-0 right-0 w-20 h-20 bg-white/10 rounded-full blur-xl -translate-y-1/2 translate-x-1/2"></div>
                            <div class="relative flex items-center justify-between">
                                <span class="text-sm font-semibold">Net Pay (Latest)</span>
                                <span class="text-2xl font-bold">$<?php echo $payroll_data ? number_format($payroll_data['net_salary'], 2) : '--'; ?></span>
                            </div>
                            <div class="relative flex items-center justify-between mt-1">
                                <span class="text-xs text-white/70">Gross</span>
                                <span class="text-xs text-white/70">$<?php echo $payroll_data ? number_format($payroll_data['gross_salary'], 2) : '--'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>

    <script>
    // Live clock
    function updateClock() {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        const el = document.getElementById('currentTime');
        if (el) el.textContent = timeStr;
    }
    setInterval(updateClock, 1000);
    </script>
</body>
</html>
