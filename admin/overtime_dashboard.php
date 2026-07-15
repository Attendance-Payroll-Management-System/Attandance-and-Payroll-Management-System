<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$stats = get_overtime_dashboard_stats($conn, $month, $year);

$has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;

$wd = $stats['by_type']['working_day'] ?? ['hours' => 0, 'count' => 0];
$we = $stats['by_type']['weekend'] ?? ['hours' => 0, 'count' => 0];
$hol = $stats['by_type']['holiday'] ?? ['hours' => 0, 'count' => 0];

$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Overtime Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ chartReady: false }" x-init="$nextTick(() => { chartReady = true })" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Overtime Dashboard";
            $page_subtitle = date('F Y', mktime(0, 0, 0, $month, 1, $year));
            ob_start();
        ?>
        <div class="flex items-center gap-2">
            <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="rounded-xl bg-white/[0.06] hover:bg-white/[0.1] text-zinc-400 hover:text-white px-3 py-2 transition text-sm"><i class="fa-solid fa-chevron-left"></i></a>
            <span class="text-sm font-semibold text-white px-2"><?php echo date('M Y', mktime(0, 0, 0, $month, 1, $year)); ?></span>
            <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="rounded-xl bg-white/[0.06] hover:bg-white/[0.1] text-zinc-400 hover:text-white px-3 py-2 transition text-sm"><i class="fa-solid fa-chevron-right"></i></a>
            <a href="?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="rounded-xl bg-blue-600/20 hover:bg-blue-600/30 text-blue-400 px-3 py-2 transition text-sm ml-1"><i class="fa-solid fa-calendar-day"></i></a>
        </div>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto space-y-6">

            <!-- Stats Cards -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total OT Hours</span>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500/20 to-purple-500/10 flex items-center justify-center"><i class="fa-solid fa-clock text-indigo-400 text-sm"></i></div>
                    </div>
                    <p class="text-2xl font-bold text-white"><?php echo number_format($stats['total_hours'] ?? 0, 1); ?>h</p>
                    <p class="text-[10px] text-zinc-500 mt-1"><?php echo $stats['total_requests'] ?? 0; ?> total requests</p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Approved</span>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500/20 to-green-500/10 flex items-center justify-center"><i class="fa-solid fa-circle-check text-emerald-400 text-sm"></i></div>
                    </div>
                    <p class="text-2xl font-bold text-emerald-400"><?php echo number_format($stats['approved_hours'] ?? 0, 1); ?>h</p>
                    <p class="text-[10px] text-zinc-500 mt-1"><?php echo $stats['approved_count'] ?? 0; ?> approved</p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Pending</span>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500/20 to-yellow-500/10 flex items-center justify-center"><i class="fa-solid fa-hourglass-half text-amber-400 text-sm"></i></div>
                    </div>
                    <p class="text-2xl font-bold text-amber-400"><?php echo number_format($stats['pending_hours'] ?? 0, 1); ?>h</p>
                    <p class="text-[10px] text-zinc-500 mt-1"><?php echo $stats['pending_count'] ?? 0; ?> pending</p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total OT Earnings</span>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500/20 to-green-500/10 flex items-center justify-center"><i class="fa-solid fa-dollar-sign text-emerald-400 text-sm"></i></div>
                    </div>
                    <p class="text-2xl font-bold text-emerald-400">$<?php echo number_format($stats['total_earnings'] ?? 0, 2); ?></p>
                    <p class="text-[10px] text-zinc-500 mt-1">approved OT pay</p>
                </div>
            </section>

            <?php if ($has_ot_type): ?>
            <!-- Type Breakdown + Monthly Cap -->
            <section class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4 text-sm"><i class="fa-solid fa-chart-pie text-blue-400 mr-2"></i>OT by Type</h3>
                    <div class="space-y-3">
                        <?php $total_type_hours = $wd['hours'] + $we['hours'] + $hol['hours']; ?>
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-blue-400 font-semibold">Working Day</span>
                                <span class="text-zinc-400"><?php echo number_format($wd['hours'], 1); ?>h (<?php echo $wd['count']; ?>)</span>
                            </div>
                            <div class="h-2 bg-white/[0.06] rounded-full overflow-hidden">
                                <div class="h-full bg-blue-500 rounded-full transition-all" style="width: <?php echo $total_type_hours > 0 ? ($wd['hours'] / $total_type_hours * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-amber-400 font-semibold">Weekend</span>
                                <span class="text-zinc-400"><?php echo number_format($we['hours'], 1); ?>h (<?php echo $we['count']; ?>)</span>
                            </div>
                            <div class="h-2 bg-white/[0.06] rounded-full overflow-hidden">
                                <div class="h-full bg-amber-500 rounded-full transition-all" style="width: <?php echo $total_type_hours > 0 ? ($we['hours'] / $total_type_hours * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-rose-400 font-semibold">Holiday</span>
                                <span class="text-zinc-400"><?php echo number_format($hol['hours'], 1); ?>h (<?php echo $hol['count']; ?>)</span>
                            </div>
                            <div class="h-2 bg-white/[0.06] rounded-full overflow-hidden">
                                <div class="h-full bg-rose-500 rounded-full transition-all" style="width: <?php echo $total_type_hours > 0 ? ($hol['hours'] / $total_type_hours * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4 text-sm"><i class="fa-solid fa-chart-simple text-purple-400 mr-2"></i>OT by Type (Chart)</h3>
                    <div class="h-48 flex items-center justify-center">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>

                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4 text-sm"><i class="fa-solid fa-gauge-high text-emerald-400 mr-2"></i>Monthly Cap Usage</h3>
                    <?php
                        $monthly_cap = (float)get_overtime_setting($conn, 'ot_monthly_max_hours', '60');
                        $used_hours = (float)($stats['approved_hours'] ?? 0);
                        $pct = $monthly_cap > 0 ? min(100, round($used_hours / $monthly_cap * 100, 1)) : 0;
                    ?>
                    <div class="text-center mb-3">
                        <span class="text-4xl font-bold <?php echo $pct >= 80 ? 'text-red-400' : ($pct >= 50 ? 'text-amber-400' : 'text-emerald-400'); ?>"><?php echo $pct; ?>%</span>
                        <p class="text-xs text-zinc-500 mt-1">of <?php echo number_format($monthly_cap, 0); ?>h cap used</p>
                    </div>
                    <div class="h-3 bg-white/[0.06] rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700 <?php echo $pct >= 80 ? 'bg-red-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-emerald-500'); ?>" style="width: <?php echo $pct; ?>%"></div>
                    </div>
                    <div class="flex justify-between text-xs text-zinc-500 mt-2">
                        <span><?php echo number_format($used_hours, 1); ?>h used</span>
                        <span><?php echo number_format(max(0, $monthly_cap - $used_hours), 1); ?>h remaining</span>
                    </div>
                </div>

                <?php
                $emp_req = $stats['by_request_type']['employee_request'] ?? ['hours' => 0, 'count' => 0, 'pay' => 0];
                $adm_req = $stats['by_request_type']['admin_assignment'] ?? ['hours' => 0, 'count' => 0, 'pay' => 0];
                $total_rt_hours = $emp_req['hours'] + $adm_req['hours'];
                ?>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4 text-sm"><i class="fa-solid fa-users-gear text-cyan-400 mr-2"></i>Request Source</h3>
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-blue-400 font-semibold">Employee Requests</span>
                                <span class="text-zinc-400"><?php echo number_format($emp_req['hours'], 1); ?>h (<?php echo $emp_req['count']; ?>)</span>
                            </div>
                            <div class="h-2 bg-white/[0.06] rounded-full overflow-hidden">
                                <div class="h-full bg-blue-500 rounded-full transition-all" style="width: <?php echo $total_rt_hours > 0 ? ($emp_req['hours'] / $total_rt_hours * 100) : 0; ?>%"></div>
                            </div>
                            <p class="text-[10px] text-zinc-500 mt-0.5">$<?php echo number_format($emp_req['pay'], 2); ?> earnings</p>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-purple-400 font-semibold">Admin Assignments</span>
                                <span class="text-zinc-400"><?php echo number_format($adm_req['hours'], 1); ?>h (<?php echo $adm_req['count']; ?>)</span>
                            </div>
                            <div class="h-2 bg-white/[0.06] rounded-full overflow-hidden">
                                <div class="h-full bg-purple-500 rounded-full transition-all" style="width: <?php echo $total_rt_hours > 0 ? ($adm_req['hours'] / $total_rt_hours * 100) : 0; ?>%"></div>
                            </div>
                            <p class="text-[10px] text-zinc-500 mt-0.5">$<?php echo number_format($adm_req['pay'], 2); ?> earnings</p>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- Daily Trend Chart -->
            <section class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                <h3 class="font-bold text-white mb-4 text-sm"><i class="fa-solid fa-chart-line text-indigo-400 mr-2"></i>Daily OT Trend (<?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>)</h3>
                <div class="h-64">
                    <canvas id="trendChart"></canvas>
                </div>
            </section>

            <!-- Top Employees -->
            <section class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-white text-sm"><i class="fa-solid fa-trophy text-amber-400 mr-2"></i>Top Employees by OT Hours</h3>
                </div>
                <?php if (empty($stats['top_employees'])): ?>
                <p class="text-zinc-500 text-sm text-center py-6">No approved overtime this month.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="py-3">#</th>
                                <th class="py-3">Employee</th>
                                <th class="py-3">Department</th>
                                <th class="py-3 text-right">Hours</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.04]">
                            <?php $rank = 1; foreach ($stats['top_employees'] as $emp): ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="py-3 font-bold <?php echo $rank === 1 ? 'text-amber-400' : ($rank === 2 ? 'text-zinc-300' : ($rank === 3 ? 'text-orange-400' : 'text-zinc-500')); ?>"><?php echo $rank++; ?></td>
                                <td class="py-3">
                                    <span class="font-medium text-white"><?php echo htmlspecialchars($emp['name']); ?></span>
                                    <span class="text-zinc-500 text-xs ml-2"><?php echo htmlspecialchars($emp['employee_code']); ?></span>
                                </td>
                                <td class="py-3 text-zinc-400"><?php echo htmlspecialchars($emp['department_name'] ?? '-'); ?></td>
                                <td class="py-3 text-right font-semibold text-purple-400"><?php echo number_format($emp['total_hours'], 1); ?>h</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($has_ot_type): ?>
    // Type Doughnut Chart
    const typeCtx = document.getElementById('typeChart');
    if (typeCtx) {
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: ['Working Day', 'Weekend', 'Holiday'],
                datasets: [{
                    data: [<?php echo $wd['hours']; ?>, <?php echo $we['hours']; ?>, <?php echo $hol['hours']; ?>],
                    backgroundColor: ['#3B82F6', '#F59E0B', '#F43F5E'],
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#a1a1aa', font: { size: 10 }, boxWidth: 10, padding: 8 } }
                }
            }
        });
    }
    <?php endif; ?>

    // Trend Line Chart
    const trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(fn($d) => date('d', strtotime($d['ot_date'])), $stats['daily_trend'])); ?>,
                datasets: [{
                    label: 'OT Hours',
                    data: <?php echo json_encode(array_map(fn($d) => (float)$d['hours'], $stats['daily_trend'])); ?>,
                    borderColor: '#8B5CF6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#8B5CF6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1,
                    pointRadius: 3,
                }, {
                    label: 'Requests',
                    data: <?php echo json_encode(array_map(fn($d) => (int)$d['requests'], $stats['daily_trend'])); ?>,
                    borderColor: '#F59E0B',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#F59E0B',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1,
                    pointRadius: 3,
                    yAxisID: 'y1',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: '#a1a1aa', font: { size: 11 }, boxWidth: 12, padding: 12 } }
                },
                scales: {
                    x: { ticks: { color: '#71717a', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } },
                    y: { ticks: { color: '#71717a', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true },
                    y1: { position: 'right', ticks: { color: '#71717a', font: { size: 10 } }, grid: { display: false }, beginAtZero: true },
                }
            }
        });
    }
});
</script>
</body>
</html>
