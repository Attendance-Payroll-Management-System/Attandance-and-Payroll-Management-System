<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$selected_month = isset($_GET['pay_month']) ? (int)$_GET['pay_month'] : (int)date('m');
$selected_year = isset($_GET['pay_year']) ? (int)$_GET['pay_year'] : (int)date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));

$sql = "SELECT p.*, e.name, e.employee_code, d.department_name
        FROM payrolls p
        JOIN employee e ON p.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE p.payroll_month = ? AND p.payroll_year = ?
        ORDER BY e.name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $selected_month, $selected_year);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_net = 0; $total_gross = 0; $total_ot = 0; $total_bonus = 0; $total_ded = 0;
foreach ($records as $r) {
    $total_net += $r['net_salary'];
    $total_gross += $r['gross_salary'];
    $total_ot += $r['ot_amount'];
    $total_bonus += $r['bonus_amount'];
    $total_ded += $r['deduction_amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Salary Report</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .report-stat {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .report-stat:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
        }
        .report-stat::after {
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
        .report-stat:hover::after { opacity: 0.12; }
        .report-stat.net::after { background: #10B981; }
        .report-stat.gross::after { background: #1E3A8A; }
        .report-stat.ot::after { background: #F59E0B; }
        .report-stat.bonus::after { background: #06B6D4; }
        .report-stat.ded::after { background: #F43F5E; }
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
        .report-card {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .report-card:hover {
            box-shadow: var(--shadow-card-hover);
        }
        .report-card::before {
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
        .report-card:hover::before { opacity: 1; }
        .table-row {
            transition: all 0.2s ease;
        }
        .table-row:hover {
            background: linear-gradient(90deg, rgba(139,92,246,0.03), rgba(217,70,239,0.02), transparent) !important;
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
    </style>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Salary Report";
            $page_subtitle = "Monthly salary breakdown per employee.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-blue-500/15 flex items-center justify-center">
                    <i class="fa-regular fa-calendar text-blue-500 text-sm"></i>
                </div>
                <select name="pay_month" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 min-w-[130px]">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-indigo-500/15 flex items-center justify-center">
                    <i class="fa-solid fa-clock-rotate-left text-indigo-500 text-sm"></i>
                </div>
                <select name="pay_year" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 min-w-[100px]">
                    <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition-all duration-200 hover:scale-105 btn-ripple">
                <i class="fa-solid fa-magnifying-glass"></i> View
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <!-- Stats Cards -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <!-- Total Net Payout -->
                <div class="report-stat net animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-emerald-500/20 to-teal-500/10">
                            <i class="fa-solid fa-dollar-sign text-emerald-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Net</span>
                            <p class="text-xl font-extrabold text-emerald-400 mt-0.5 truncate">$<?php echo number_format($total_net, 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Total Gross -->
                <div class="report-stat gross animate-fade-in-up stagger-2">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-blue-500/20 to-indigo-500/10">
                            <i class="fa-solid fa-coins text-blue-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Gross</span>
                            <p class="text-xl font-extrabold text-blue-400 mt-0.5 truncate">$<?php echo number_format($total_gross, 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Total Overtime -->
                <div class="report-stat ot animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-amber-500/20 to-orange-500/10">
                            <i class="fa-solid fa-clock text-amber-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total OT</span>
                            <p class="text-xl font-extrabold text-amber-400 mt-0.5 truncate">$<?php echo number_format($total_ot, 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Total Bonuses -->
                <div class="report-stat bonus animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-cyan-500/20 to-blue-500/10">
                            <i class="fa-solid fa-gift text-cyan-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Bonus</span>
                            <p class="text-xl font-extrabold text-cyan-400 mt-0.5 truncate">$<?php echo number_format($total_bonus, 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Total Deductions -->
                <div class="report-stat ded animate-fade-in-up stagger-5">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-rose-500/20 to-pink-500/10">
                            <i class="fa-solid fa-chart-line text-rose-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Ded.</span>
                            <p class="text-xl font-extrabold text-rose-400 mt-0.5 truncate">$<?php echo number_format($total_ded, 2); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Salary Details Table -->
            <section class="report-card animate-fade-in-up stagger-6">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-chart-column text-blue-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Salary Details</h2>
                            <p class="text-xs text-zinc-500 mt-0.5"><?php echo $month_name . ' ' . $selected_year; ?></p>
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20">
                        <i class="fa-solid fa-users text-indigo-400 text-xs"></i>
                        <span class="text-xs font-semibold text-indigo-400"><?php echo count($records); ?> employees</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4">Code</th>
                                <th class="px-6 py-4">Department</th>
                                <th class="px-6 py-4 text-right">Basic</th>
                                <th class="px-6 py-4 text-right">OT</th>
                                <th class="px-6 py-4 text-right">Bonus</th>
                                <th class="px-6 py-4 text-right">Deduction</th>
                                <th class="px-6 py-4 text-right">Gross</th>
                                <th class="px-6 py-4 text-right font-bold">Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="9" class="px-6 py-16 text-center">
                                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500/10 to-indigo-500/10 flex items-center justify-center mx-auto mb-4">
                                        <i class="fa-solid fa-inbox text-2xl text-zinc-500"></i>
                                    </div>
                                    <p class="text-zinc-400 font-medium">No salary data for <?php echo $month_name . ' ' . $selected_year; ?></p>
                                    <p class="text-zinc-500 text-sm mt-2">Go to <a href="payroll.php" class="text-blue-400 hover:text-blue-300 font-semibold transition-colors underline underline-offset-2">Payroll</a> to generate salaries for this period.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($records as $idx => $r): ?>
                                <tr class="table-row animate-fade-in-up" style="animation-delay: <?php echo 0.05 + ($idx * 0.03); ?>s;">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-500 text-white flex items-center justify-center text-[10px] font-bold shadow-lg shadow-blue-500/20 shrink-0">
                                                <?php echo strtoupper(substr($r['name'], 0, 2)); ?>
                                            </div>
                                            <div>
                                                <div class="font-semibold text-white"><?php echo htmlspecialchars($r['name']); ?></div>
                                                <div class="text-[11px] text-zinc-500 font-medium"><?php echo htmlspecialchars($r['employee_code']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-zinc-400 font-mono text-xs"><?php echo htmlspecialchars($r['employee_code']); ?></td>
                                    <td class="px-6 py-4">
                                        <?php if ($r['department_name']): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-blue-500/10 border border-blue-500/20 text-xs font-medium text-blue-400">
                                                <?php echo htmlspecialchars($r['department_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right font-mono text-white font-medium">$<?php echo number_format($r['basic_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if ($r['ot_amount'] > 0): ?>
                                            <span class="inline-flex items-center gap-1 font-mono text-amber-400 font-medium">
                                                <i class="fa-solid fa-plus text-[9px]"></i>$<?php echo number_format($r['ot_amount'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if ($r['bonus_amount'] > 0): ?>
                                            <span class="inline-flex items-center gap-1 font-mono text-emerald-400 font-medium">
                                                <i class="fa-solid fa-plus text-[9px]"></i>$<?php echo number_format($r['bonus_amount'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if ($r['deduction_amount'] > 0): ?>
                                            <span class="inline-flex items-center gap-1 font-mono text-rose-400 font-medium">
                                                <i class="fa-solid fa-minus text-[9px]"></i>$<?php echo number_format($r['deduction_amount'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right font-mono text-white font-bold">$<?php echo number_format($r['gross_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right">
                                        <span class="net-highlight">$<?php echo number_format($r['net_salary'], 2); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
