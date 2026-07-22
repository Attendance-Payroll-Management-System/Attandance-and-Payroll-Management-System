<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$stats = get_dashboard_payroll_stats($conn);

$total_employees = $stats['total_employees'];
$total_net = $stats['total_net'];
$total_ot = $stats['total_ot'];
$total_bonus = $stats['total_bonus'];
$total_ded = $stats['total_ded'];
$total_pending = $stats['total_pending'];
$total_paid = $stats['total_paid'];
$total_approved = $stats['total_approved'];
$status_counts = $stats['status_counts'];
$monthly_trend = $stats['monthly_trend'];
$total_payrolls = $stats['total_payrolls'];

$approval_rate = $total_payrolls > 0 ? round((($total_approved + $total_paid) / $total_payrolls) * 100, 1) : 0;

$trend_labels = array_column($monthly_trend, 'month');
$trend_totals = array_column($monthly_trend, 'total');

$recent_payrolls = $conn->prepare("
    SELECT p.*, e.name, e.employee_code
    FROM payrolls p
    JOIN employee e ON p.employee_id = e.id
    ORDER BY p.generated_date DESC, e.name ASC
    LIMIT 10
");
$recent_payrolls->execute();
$recent_data = $recent_payrolls->get_result()->fetch_all(MYSQLI_ASSOC);
$recent_payrolls->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Payroll Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .dashboard-stat {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .dashboard-stat:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
        }
        .dashboard-stat::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            opacity: 0.06;
            transition: opacity 0.3s;
        }
        .dashboard-stat:hover::after { opacity: 0.12; }
        .dashboard-stat.blue::after { background: #3B82F6; }
        .dashboard-stat.emerald::after { background: #10B981; }
        .dashboard-stat.amber::after { background: #F59E0B; }
        .dashboard-stat.indigo::after { background: #6366F1; }
        .dashboard-stat.rose::after { background: #F43F5E; }
        .dashboard-stat.orange::after { background: #F97316; }
        .stat-icon-box {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .sheet-card {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sheet-card:hover {
            box-shadow: var(--shadow-card-hover);
        }
        .sheet-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #1E3A8A, #4F46E5, #F59E0B);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sheet-card:hover::before { opacity: 1; }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .table-row {
            transition: all 0.2s ease;
        }
        .table-row:hover {
            background: linear-gradient(90deg, rgba(139,92,246,0.03), rgba(217,70,239,0.02), transparent) !important;
        }
        .employee-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .employee-avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #1E3A8A, #4F46E5);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(139,92,246,0.2);
        }
        .net-highlight {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.625rem;
            background: rgba(139,92,246,0.1);
            border: 1px solid rgba(139,92,246,0.2);
            color: #A78BFA;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }
        .quick-stat-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .quick-stat-item:last-child { border-bottom: none; }
    </style>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Payroll Dashboard";
            $page_subtitle = "Enterprise overview";
            $page_actions = '';
        ?>
        <?php include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <!-- Stats Cards Row (6 cards) -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-5 mb-8">
                <!-- Total Employees -->
                <div class="dashboard-stat blue animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-blue-500/20 to-indigo-500/10">
                            <i class="fa-solid fa-users text-blue-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Employees</span>
                            <p class="text-xl font-extrabold text-blue-400 mt-0.5 truncate"><?php echo number_format($total_employees); ?></p>
                            <p class="text-[10px] text-zinc-500 mt-0.5">Active workforce</p>
                        </div>
                    </div>
                </div>

                <!-- Monthly Payroll -->
                <div class="dashboard-stat emerald animate-fade-in-up stagger-2">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-emerald-500/20 to-teal-500/10">
                            <i class="fa-solid fa-sack-dollar text-emerald-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Monthly Payroll</span>
                            <p class="text-xl font-extrabold text-emerald-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_net, 2); ?></p>
                            <p class="text-[10px] text-zinc-500 mt-0.5">Total net pay</p>
                        </div>
                    </div>
                </div>

                <!-- Overtime Cost -->
                <div class="dashboard-stat amber animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-amber-500/20 to-orange-500/10">
                            <i class="fa-solid fa-clock text-amber-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Overtime Cost</span>
                            <p class="text-xl font-extrabold text-amber-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_ot, 2); ?></p>
                            <p class="text-[10px] text-zinc-500 mt-0.5">This month</p>
                        </div>
                    </div>
                </div>

                <!-- Total Bonuses -->
                <div class="dashboard-stat indigo animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-indigo-500/20 to-blue-500/10">
                            <i class="fa-solid fa-gift text-indigo-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Bonuses</span>
                            <p class="text-xl font-extrabold text-indigo-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_bonus, 2); ?></p>
                            <p class="text-[10px] text-zinc-500 mt-0.5">Disbursed</p>
                        </div>
                    </div>
                </div>

                <!-- Total Deductions -->
                <div class="dashboard-stat rose animate-fade-in-up stagger-5">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-rose-500/20 to-pink-500/10">
                            <i class="fa-solid fa-chart-line text-rose-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Deductions</span>
                            <p class="text-xl font-extrabold text-rose-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_ded, 2); ?></p>
                            <p class="text-[10px] text-zinc-500 mt-0.5">Withheld</p>
                        </div>
                    </div>
                </div>

                <!-- Pending Payrolls -->
                <div class="dashboard-stat orange animate-fade-in-up stagger-6">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-orange-500/20 to-amber-500/10">
                            <i class="fa-solid fa-hourglass text-orange-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Pending Payrolls</span>
                            <div class="flex items-center gap-2 mt-0.5">
                                <p class="text-xl font-extrabold text-orange-400 truncate"><?php echo number_format($total_pending); ?></p>
                                <?php if ($total_pending > 0): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-orange-500/20 text-orange-400 border border-orange-500/20">
                                    <?php echo $total_pending; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-[10px] text-zinc-500 mt-0.5">Awaiting action</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Status Distribution Cards -->
            <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
                <!-- Draft -->
                <div class="glass-strong rounded-xl p-4 animate-fade-in-up stagger-1 text-center">
                    <div class="w-10 h-10 rounded-lg bg-zinc-500/15 flex items-center justify-center mx-auto mb-2">
                        <i class="fa-solid fa-pen-to-square text-zinc-400 text-sm"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-zinc-300"><?php echo $status_counts['Draft']; ?></p>
                    <span class="inline-flex items-center gap-1 mt-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-zinc-500/15 text-zinc-400 border border-zinc-500/20">
                        Draft
                    </span>
                </div>

                <!-- Generated -->
                <div class="glass-strong rounded-xl p-4 animate-fade-in-up stagger-2 text-center">
                    <div class="w-10 h-10 rounded-lg bg-blue-500/15 flex items-center justify-center mx-auto mb-2">
                        <i class="fa-solid fa-calculator text-blue-400 text-sm"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-blue-400"><?php echo $status_counts['Generated']; ?></p>
                    <span class="inline-flex items-center gap-1 mt-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-blue-500/15 text-blue-400 border border-blue-500/20">
                        Generated
                    </span>
                </div>

                <!-- Reviewed -->
                <div class="glass-strong rounded-xl p-4 animate-fade-in-up stagger-3 text-center">
                    <div class="w-10 h-10 rounded-lg bg-cyan-500/15 flex items-center justify-center mx-auto mb-2">
                        <i class="fa-solid fa-magnifying-glass text-cyan-400 text-sm"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-cyan-400"><?php echo $status_counts['Reviewed']; ?></p>
                    <span class="inline-flex items-center gap-1 mt-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-cyan-500/15 text-cyan-400 border border-cyan-500/20">
                        Reviewed
                    </span>
                </div>

                <!-- Approved -->
                <div class="glass-strong rounded-xl p-4 animate-fade-in-up stagger-4 text-center">
                    <div class="w-10 h-10 rounded-lg bg-emerald-500/15 flex items-center justify-center mx-auto mb-2">
                        <i class="fa-solid fa-check-circle text-emerald-400 text-sm"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-emerald-400"><?php echo $status_counts['Approved']; ?></p>
                    <span class="inline-flex items-center gap-1 mt-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/15 text-emerald-400 border border-emerald-500/20">
                        Approved
                    </span>
                </div>

                <!-- Paid -->
                <div class="glass-strong rounded-xl p-4 animate-fade-in-up stagger-5 text-center">
                    <div class="w-10 h-10 rounded-lg bg-purple-500/15 flex items-center justify-center mx-auto mb-2">
                        <i class="fa-solid fa-sack-dollar text-purple-400 text-sm"></i>
                    </div>
                    <p class="text-2xl font-extrabold text-purple-400"><?php echo $status_counts['Paid']; ?></p>
                    <span class="inline-flex items-center gap-1 mt-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-purple-500/15 text-purple-400 border border-purple-500/20">
                        Paid
                    </span>
                </div>
            </section>

            <!-- Two-Column Layout: Chart + Quick Stats -->
            <section class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
                <!-- Monthly Payroll Trend Chart -->
                <div class="sheet-card animate-fade-in-up stagger-1">
                    <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                                <i class="fa-solid fa-chart-line text-blue-500"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-white text-lg">Payroll Trend</h2>
                                <p class="text-xs text-zinc-500 mt-0.5">Last 6 months overview</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20">
                            <i class="fa-solid fa-calendar text-indigo-400 text-xs"></i>
                            <span class="text-xs font-semibold text-indigo-400"><?php echo date('Y'); ?></span>
                        </span>
                    </div>
                    <div class="p-6" x-data x-init="$nextTick(() => {
                        const isDark = document.documentElement.classList.contains('dark');
                        const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
                        const textColor = isDark ? '#a1a1aa' : '#64748b';
                        const font = { family: \"'Inter', sans-serif\" };
                        new Chart(document.getElementById('payrollTrendChart'), {
                            type: 'line',
                            data: {
                                labels: <?php echo json_encode($trend_labels); ?>,
                                datasets: [{
                                    label: 'Net Payroll ($)',
                                    data: <?php echo json_encode($trend_totals); ?>,
                                    borderColor: '#6366F1',
                                    backgroundColor: isDark ? 'rgba(99,102,241,0.1)' : 'rgba(99,102,241,0.05)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4,
                                    pointBackgroundColor: '#6366F1',
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    pointRadius: 5,
                                    pointHoverRadius: 7,
                                    pointHoverBackgroundColor: '#818CF8',
                                    pointHoverBorderWidth: 3
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        backgroundColor: isDark ? 'rgba(15,23,42,0.95)' : 'rgba(255,255,255,0.95)',
                                        titleColor: isDark ? '#F1F5F9' : '#1E293B',
                                        bodyColor: isDark ? '#CBD5E1' : '#475569',
                                        borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
                                        borderWidth: 1,
                                        cornerRadius: 12,
                                        padding: 12,
                                        callbacks: {
                                            label: function(ctx) { return '$' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}); }
                                        }
                                    }
                                },
                                scales: {
                                    x: { ticks: { color: textColor, font: { ...font, size: 11 } }, grid: { color: gridColor } },
                                    y: { ticks: { color: textColor, font: { ...font, size: 10 }, callback: v => '$' + (v/1000).toFixed(0) + 'k' }, grid: { color: gridColor }, beginAtZero: true }
                                }
                            }
                        });
                    })">
                        <div class="relative h-64">
                            <canvas id="payrollTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats Summary -->
                <div class="sheet-card animate-fade-in-up stagger-2">
                    <div class="p-6 border-b border-white/[0.06] flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-chart-pie text-emerald-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Quick Stats</h2>
                            <p class="text-xs text-zinc-500 mt-0.5">Key performance metrics</p>
                        </div>
                    </div>
                    <div class="p-6 space-y-0">
                        <div class="quick-stat-item">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-purple-500/15 flex items-center justify-center">
                                    <i class="fa-solid fa-sack-dollar text-purple-400 text-sm"></i>
                                </div>
                                <span class="text-sm text-zinc-300 font-medium">Paid Count</span>
                            </div>
                            <span class="text-sm font-bold text-purple-400"><?php echo $total_paid; ?> <span class="text-zinc-500 font-normal text-xs">/ <?php echo $total_payrolls; ?></span></span>
                        </div>

                        <div class="quick-stat-item">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-emerald-500/15 flex items-center justify-center">
                                    <i class="fa-solid fa-percent text-emerald-400 text-sm"></i>
                                </div>
                                <span class="text-sm text-zinc-300 font-medium">Approval Rate</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-emerald-400"><?php echo $approval_rate; ?>%</span>
                                <div class="w-16 h-1.5 bg-white/[0.06] rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-emerald-500 to-teal-400 rounded-full" style="width: <?php echo $approval_rate; ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <div class="quick-stat-item">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-orange-500/15 flex items-center justify-center">
                                    <i class="fa-solid fa-hourglass-half text-orange-400 text-sm"></i>
                                </div>
                                <span class="text-sm text-zinc-300 font-medium">Pending Action</span>
                            </div>
                            <span class="text-sm font-bold text-orange-400"><?php echo $total_pending; ?></span>
                        </div>

                        <div class="quick-stat-item">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-blue-500/15 flex items-center justify-center">
                                    <i class="fa-solid fa-calculator text-blue-400 text-sm"></i>
                                </div>
                                <span class="text-sm text-zinc-300 font-medium">Generated</span>
                            </div>
                            <span class="text-sm font-bold text-blue-400"><?php echo $status_counts['Generated']; ?></span>
                        </div>

                        <div class="quick-stat-item">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-cyan-500/15 flex items-center justify-center">
                                    <i class="fa-solid fa-magnifying-glass text-cyan-400 text-sm"></i>
                                </div>
                                <span class="text-sm text-zinc-300 font-medium">Reviewed</span>
                            </div>
                            <span class="text-sm font-bold text-cyan-400"><?php echo $status_counts['Reviewed']; ?></span>
                        </div>

                        <div class="quick-stat-item">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-amber-500/15 flex items-center justify-center">
                                    <i class="fa-solid fa-clock text-amber-400 text-sm"></i>
                                </div>
                                <span class="text-sm text-zinc-300 font-medium">OT Disbursed</span>
                            </div>
                            <span class="text-sm font-bold text-amber-400 font-mono"><?php echo $currency; ?> <?php echo number_format($total_ot, 2); ?></span>
                        </div>

                        <div class="quick-stat-item">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-rose-500/15 flex items-center justify-center">
                                    <i class="fa-solid fa-chart-line text-rose-400 text-sm"></i>
                                </div>
                                <span class="text-sm text-zinc-300 font-medium">Deductions</span>
                            </div>
                            <span class="text-sm font-bold text-rose-400 font-mono"><?php echo $currency; ?> <?php echo number_format($total_ded, 2); ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Recent Payroll Activity -->
            <section class="sheet-card animate-fade-in-up stagger-3">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-clock-rotate-left text-blue-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Recent Payroll Activity</h2>
                            <p class="text-xs text-zinc-500 mt-0.5">Last 10 payroll records</p>
                        </div>
                    </div>
                    <a href="payroll.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-400 text-xs font-semibold hover:bg-blue-500/20 transition-colors">
                        <i class="fa-solid fa-arrow-right"></i> Go to Payroll
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($recent_data)): ?>
                    <!-- Empty State -->
                    <div class="px-6 py-20 text-center">
                        <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-500/10 to-indigo-500/10 flex items-center justify-center mx-auto mb-5">
                            <i class="fa-solid fa-file-invoice-dollar text-3xl text-zinc-500"></i>
                        </div>
                        <p class="text-zinc-400 font-semibold text-lg mb-1">No Payroll Data</p>
                        <p class="text-zinc-500 text-sm max-w-md mx-auto">No payroll records found yet. Head to the <strong class="text-blue-400">Payroll Processing</strong> page to generate salaries for your employees.</p>
                        <a href="payroll.php" class="inline-flex items-center gap-2 mt-5 px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-sm font-semibold hover:shadow-lg hover:shadow-blue-500/25 transition-all duration-200 hover:-translate-y-0.5">
                            <i class="fa-solid fa-bolt"></i> Generate Payroll
                        </a>
                    </div>
                    <?php else: ?>
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-4 py-4">Code</th>
                                <th class="px-4 py-4">Month / Year</th>
                                <th class="px-4 py-4 text-right">Net Salary</th>
                                <th class="px-4 py-4 text-center">Status</th>
                                <th class="px-4 py-4">Generated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php foreach ($recent_data as $idx => $row): ?>
                            <tr class="table-row animate-fade-in-up" style="animation-delay: <?php echo 0.05 + ($idx * 0.03); ?>s;">
                                <td class="px-6 py-4">
                                    <div class="employee-cell">
                                        <div class="employee-avatar">
                                            <?php echo strtoupper(substr($row['name'], 0, 2)); ?>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-white"><?php echo htmlspecialchars($row['name']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="text-xs text-zinc-400 font-mono"><?php echo htmlspecialchars($row['employee_code']); ?></span>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="text-xs text-zinc-300 font-medium"><?php echo date('F', mktime(0,0,0,$row['payroll_month'],1)); ?> <?php echo $row['payroll_year']; ?></span>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <span class="net-highlight"><?php echo $currency; ?> <?php echo number_format($row['net_salary'], 2); ?></span>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="status-badge <?php echo get_payroll_status_badge($row['status'] ?? 'Generated'); ?>">
                                        <i class="fa-solid <?php echo get_payroll_status_icon($row['status'] ?? 'Generated'); ?> text-[10px]"></i>
                                        <?php echo htmlspecialchars($row['status'] ?? 'Generated'); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="text-xs text-zinc-500"><?php echo $row['generated_date'] ? date('M d, Y', strtotime($row['generated_date'])) : '—'; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>

        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> HNIN AKARI NWE</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>