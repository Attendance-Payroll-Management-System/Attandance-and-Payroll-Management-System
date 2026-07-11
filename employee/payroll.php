<?php
session_start();
require_once "../config/db.php";
if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }

$employee_id = $_SESSION['employee_id'];
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : 0;

$payrolls = $conn->prepare("
    SELECT p.* FROM payrolls p
    WHERE p.employee_id = ? AND p.payroll_year = ?
    ORDER BY p.payroll_month DESC
");
$payrolls->bind_param("ii", $employee_id, $selected_year);
$payrolls->execute();
$payroll_data = $payrolls->get_result()->fetch_all(MYSQLI_ASSOC);
$payrolls->close();

$annual = $conn->prepare("SELECT * FROM annual_payrolls WHERE employee_id = ? AND payroll_year = ?");
$annual->bind_param("ii", $employee_id, $selected_year);
$annual->execute();
$annual_data = $annual->get_result()->fetch_assoc();
$annual->close();

$totals = ['basic' => 0, 'ot' => 0, 'bonus' => 0, 'ded' => 0, 'gross' => 0, 'net' => 0, 'allowance' => 0, 'tax' => 0, 'leave_ded' => 0, 'late_ded' => 0, 'unpaid_leave_ded' => 0];
foreach ($payroll_data as $p) {
    $totals['basic'] += $p['basic_salary'];
    $totals['ot'] += $p['ot_amount'];
    $totals['bonus'] += $p['bonus_amount'];
    $totals['ded'] += $p['deduction_amount'];
    $totals['gross'] += $p['gross_salary'];
    $totals['net'] += $p['net_salary'];
    $totals['allowance'] += $p['allowance_amount'] ?? 0;
    $totals['tax'] += $p['tax_amount'] ?? 0;
    $totals['leave_ded'] += $p['leave_deduction'] ?? 0;
    $totals['late_ded'] += $p['late_deduction'] ?? 0;
    $totals['unpaid_leave_ded'] += $p['unpaid_leave_deduction'] ?? 0;
}

$latest_payroll = !empty($payroll_data) ? $payroll_data[0] : null;

$employee = $conn->prepare("SELECT e.name, e.employee_code, e.basic_salary, d.department_name, p.position_name, epi.allowance FROM employee e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN positions p ON e.position_id = p.id LEFT JOIN employee_personal_info epi ON e.id = epi.employee_id WHERE e.id = ?");
$employee->bind_param("i", $employee_id);
$employee->execute();
$emp_info = $employee->get_result()->fetch_assoc();
$employee->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · My Payroll</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .payroll-stat {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .payroll-stat:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
        }
        .payroll-stat::after {
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
        .payroll-stat:hover::after { opacity: 0.12; }
        .payroll-stat.basic::after { background: #1E3A8A; }
        .payroll-stat.allowance::after { background: #F59E0B; }
        .payroll-stat.ytd::after { background: #10B981; }
        .payroll-stat.gross::after { background: #4F46E5; }
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
        .latest-slip-card {
            position: relative;
            overflow: hidden;
            border-radius: 1.25rem;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #7C3AED 0%, #C026D3 50%, #F59E0B 100%);
            box-shadow: 0 20px 50px rgba(139,92,246,0.3), 0 8px 20px rgba(0,0,0,0.15);
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .latest-slip-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 25px 60px rgba(139,92,246,0.4), 0 10px 25px rgba(0,0,0,0.2);
        }
        .latest-slip-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
            pointer-events: none;
        }
        .slip-item {
            padding: 0.75rem;
            border-radius: 0.75rem;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(8px);
            transition: all 0.2s ease;
        }
        .slip-item:hover {
            background: rgba(255,255,255,0.15);
        }
        .net-highlight {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 1rem;
            padding: 1rem;
            text-align: center;
            backdrop-filter: blur(8px);
        }
        .annual-card {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1.25rem;
            padding: 1.5rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .annual-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
        }
        .annual-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #10B981, #06B6D4, #3B82F6);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .annual-card:hover::before { opacity: 1; }
        .history-table {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .history-table:hover {
            box-shadow: var(--shadow-card-hover);
        }
        .table-row {
            transition: all 0.2s ease;
        }
        .table-row:hover {
            background: linear-gradient(90deg, rgba(139,92,246,0.03), rgba(217,70,239,0.02), transparent) !important;
        }
        .download-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.625rem;
            background: linear-gradient(135deg, rgba(245,158,11,0.15), rgba(249,115,22,0.1));
            color: #F59E0B;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 2px 8px rgba(245,158,11,0.15);
        }
        .download-pill:hover {
            background: linear-gradient(135deg, rgba(245,158,11,0.25), rgba(249,115,22,0.2));
            transform: scale(1.1);
            box-shadow: 0 4px 16px rgba(245,158,11,0.25);
        }
    </style>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php $sidebar_role = 'employee'; include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "My Payroll";
            $page_subtitle = "View salary breakdowns and payslip history.";
            ob_start();
        ?>
        <form method="GET" class="flex items-center gap-3 glass-strong rounded-xl p-3">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-emerald-500/15 flex items-center justify-center">
                    <i class="fa-solid fa-clock-rotate-left text-emerald-500 text-sm"></i>
                </div>
                <select name="year" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-emerald-500/30 min-w-[100px]" onchange="this.form.submit()">
                    <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto page-content w-full">

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-7">
                <!-- Basic Salary -->
                <div class="payroll-stat basic animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-sky-500/20 to-cyan-500/10">
                            <i class="fa-solid fa-money-bill-wave text-sky-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Basic Salary</span>
                            <p class="text-xl font-extrabold text-white mt-0.5 truncate">$<?php echo number_format($emp_info['basic_salary'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Allowance -->
                <div class="payroll-stat allowance animate-fade-in-up stagger-2">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-amber-500/20 to-orange-500/10">
                            <i class="fa-solid fa-hand-holding-dollar text-amber-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Allowance</span>
                            <p class="text-xl font-extrabold text-amber-400 mt-0.5 truncate">$<?php echo number_format($emp_info['allowance'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Year to Date -->
                <div class="payroll-stat ytd animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-emerald-500/20 to-teal-500/10">
                            <i class="fa-solid fa-chart-line text-emerald-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Year to Date</span>
                            <p class="text-xl font-extrabold text-emerald-400 mt-0.5 truncate">$<?php echo number_format($totals['net'], 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- YTD Gross -->
                <div class="payroll-stat gross animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-indigo-500/20 to-pink-500/10">
                            <i class="fa-solid fa-sack-dollar text-indigo-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">YTD Gross</span>
                            <p class="text-xl font-extrabold text-indigo-400 mt-0.5 truncate">$<?php echo number_format($totals['gross'], 2); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Latest Payslip -->
            <?php if ($latest_payroll): ?>
            <div class="latest-slip-card animate-fade-in-up stagger-3">
                <div class="flex items-center justify-between mb-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center backdrop-blur-sm">
                            <i class="fa-solid fa-file-invoice text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white">Latest Payslip</h3>
                            <span class="text-sm text-white/70"><?php echo date('F', mktime(0,0,0,$latest_payroll['payroll_month'],1)); ?> <?php echo $latest_payroll['payroll_year']; ?></span>
                        </div>
                    </div>
                    <a href="download_slip.php?pid=<?php echo $latest_payroll['id']; ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/20 hover:bg-white/30 text-white text-sm font-semibold backdrop-blur-sm transition-all duration-200 hover:scale-105">
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                    <div class="slip-item">
                        <span class="text-[11px] font-semibold text-white/60 uppercase tracking-wider">Basic</span>
                        <p class="text-lg font-bold text-white mt-0.5">$<?php echo number_format($latest_payroll['basic_salary'], 2); ?></p>
                    </div>
                    <div class="slip-item">
                        <span class="text-[11px] font-semibold text-white/60 uppercase tracking-wider">OT</span>
                        <p class="text-lg font-bold text-white mt-0.5">$<?php echo number_format($latest_payroll['ot_amount'] ?? 0, 2); ?></p>
                    </div>
                    <div class="slip-item">
                        <span class="text-[11px] font-semibold text-white/60 uppercase tracking-wider">Allowance</span>
                        <p class="text-lg font-bold text-white mt-0.5">$<?php echo number_format($latest_payroll['allowance_amount'] ?? 0, 2); ?></p>
                    </div>
                    <div class="slip-item">
                        <span class="text-[11px] font-semibold text-white/60 uppercase tracking-wider">Bonus</span>
                        <p class="text-lg font-bold text-white mt-0.5">$<?php echo number_format($latest_payroll['bonus_amount'] ?? 0, 2); ?></p>
                    </div>
                    <div class="slip-item">
                        <span class="text-[11px] font-semibold text-white/60 uppercase tracking-wider">Deductions</span>
                        <p class="text-lg font-bold text-rose-300 mt-0.5">-$<?php echo number_format($latest_payroll['deduction_amount'] ?? 0, 2); ?></p>
                    </div>
                    <div class="slip-item">
                        <span class="text-[11px] font-semibold text-white/60 uppercase tracking-wider">Leave Ded.</span>
                        <p class="text-lg font-bold text-rose-300 mt-0.5">-$<?php echo number_format($latest_payroll['leave_deduction'] ?? 0, 2); ?></p>
                    </div>
                    <div class="slip-item">
                        <span class="text-[11px] font-semibold text-white/60 uppercase tracking-wider">Tax</span>
                        <p class="text-lg font-bold text-rose-300 mt-0.5">-$<?php echo number_format($latest_payroll['tax_amount'] ?? 0, 2); ?></p>
                    </div>
                    <div class="net-highlight">
                        <span class="text-[11px] font-semibold text-white/80 uppercase tracking-wider">Net Pay</span>
                        <p class="text-2xl font-extrabold text-white mt-0.5">$<?php echo number_format($latest_payroll['net_salary'], 2); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Annual Summary -->
            <?php if ($annual_data): ?>
            <div class="annual-card mb-6 animate-fade-in-up stagger-4">
                <div class="flex items-center justify-between mb-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-chart-line text-emerald-500"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-white">Annual Summary</h3>
                            <span class="text-xs text-zinc-500"><?php echo $selected_year; ?> fiscal year</span>
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-sky-500/10 to-cyan-500/10 border border-sky-500/20">
                        <i class="fa-solid fa-coins text-sky-400 text-sm"></i>
                        <span class="text-lg font-extrabold text-sky-400">$<?php echo number_format($annual_data['net_annual_salary'], 2); ?></span>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="p-3 rounded-xl bg-white/[0.04] border border-white/[0.06]">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Salary</span>
                        <p class="text-base font-bold text-white mt-1">$<?php echo number_format($annual_data['total_salary'], 2); ?></p>
                    </div>
                    <div class="p-3 rounded-xl bg-amber-500/5 border border-amber-500/10">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-amber-500/70">OT</span>
                        <p class="text-base font-bold text-amber-400 mt-1">+$<?php echo number_format($annual_data['total_ot'], 2); ?></p>
                    </div>
                    <div class="p-3 rounded-xl bg-emerald-500/5 border border-emerald-500/10">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-500/70">Bonuses</span>
                        <p class="text-base font-bold text-emerald-400 mt-1">+$<?php echo number_format($annual_data['total_bonus'], 2); ?></p>
                    </div>
                    <div class="p-3 rounded-xl bg-rose-500/5 border border-rose-500/10">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-rose-500/70">Deductions</span>
                        <p class="text-base font-bold text-rose-400 mt-1">-$<?php echo number_format($annual_data['total_deduction'], 2); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payroll History -->
            <div class="history-table animate-fade-in-up stagger-5">
                <div class="p-5 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500/20 to-cyan-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-clock-rotate-left text-sky-500"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-white">Payroll History</h3>
                            <span class="text-xs text-zinc-500"><?php echo count($payroll_data); ?> record<?php echo count($payroll_data) !== 1 ? 's' : ''; ?></span>
                        </div>
                    </div>
                </div>
                <?php if (empty($payroll_data)): ?>
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-sky-500/10 to-cyan-500/10 flex items-center justify-center mx-auto mb-4">
                            <i class="fa-solid fa-inbox text-2xl text-zinc-500"></i>
                        </div>
                        <p class="text-zinc-400 font-medium">No payroll records found for <?php echo $selected_year; ?>.</p>
                        <p class="text-zinc-500 text-sm mt-1">Your salary history will appear here once processed.</p>
                    </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Period</th>
                                <th class="px-6 py-4 text-right">Basic</th>
                                <th class="px-6 py-4 text-right">OT</th>
                                <th class="px-6 py-4 text-right">Bonus</th>
                                <th class="px-6 py-4 text-right">Deductions</th>
                                <th class="px-6 py-4 text-right">Gross</th>
                                <th class="px-6 py-4 text-right font-bold">Net</th>
                                <th class="px-6 py-4 text-center">Slip</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php foreach ($payroll_data as $idx => $p): ?>
                            <tr class="table-row animate-fade-in-up" style="animation-delay: <?php echo 0.05 + ($idx * 0.03); ?>s;">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-sky-500/15 to-cyan-500/10 flex items-center justify-center shrink-0">
                                            <i class="fa-regular fa-calendar text-sky-400 text-xs"></i>
                                        </div>
                                        <span class="font-semibold text-white"><?php echo date('F', mktime(0,0,0,$p['payroll_month'],1)); ?> <?php echo $p['payroll_year']; ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-white font-medium">$<?php echo number_format($p['basic_salary'], 2); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <?php if ($p['ot_amount'] > 0): ?>
                                        <span class="inline-flex items-center gap-1 font-mono text-amber-400 font-medium">
                                            <i class="fa-solid fa-plus text-[9px]"></i>$<?php echo number_format($p['ot_amount'], 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="font-mono text-zinc-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <?php if ($p['bonus_amount'] > 0): ?>
                                        <span class="inline-flex items-center gap-1 font-mono text-emerald-400 font-medium">
                                            <i class="fa-solid fa-plus text-[9px]"></i>$<?php echo number_format($p['bonus_amount'], 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="font-mono text-zinc-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <?php if ($p['deduction_amount'] > 0): ?>
                                        <span class="inline-flex items-center gap-1 font-mono text-rose-400 font-medium">
                                            <i class="fa-solid fa-minus text-[9px]"></i>$<?php echo number_format($p['deduction_amount'], 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="font-mono text-zinc-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-white font-medium">$<?php echo number_format($p['gross_salary'], 2); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-500/10 border border-sky-500/20 font-mono text-sky-400 font-bold">
                                        <i class="fa-solid fa-dollar-sign text-[10px]"></i><?php echo number_format($p['net_salary'], 2); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="download_slip.php?pid=<?php echo $p['id']; ?>" title="Download Salary Slip" class="download-pill">
                                        <i class="fa-solid fa-file-pdf"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-6 lg:px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> HNIN AKARI NWE</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>
