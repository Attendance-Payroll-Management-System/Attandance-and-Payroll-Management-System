<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';
require_once '../config/dompdf_generator.php';

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));

// PDF download
if (isset($_GET['download_pdf']) && isset($_GET['pid'])) {
    $pid = (int)$_GET['pid'];
    $stmt = $conn->prepare("
        SELECT p.*, e.name, e.employee_code
        FROM payrolls p JOIN employee e ON p.employee_id = e.id WHERE p.id = ?
    ");
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $slip = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($slip) {
        // Fetch AWOL deduction amount for this employee and month
        $awol_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as awol_total FROM deductions WHERE employee_id = ? AND deduction_date BETWEEN ? AND ? AND remarks = 'Auto Pension Fund Deduction for Unauthorized Absence'");
        $month_start_pdf = sprintf('%04d-%02d-01', $selected_year, $selected_month);
        $month_end_pdf = date('Y-m-t', strtotime($month_start_pdf));
        $awol_stmt->bind_param('iss', $slip['employee_id'], $month_start_pdf, $month_end_pdf);
        $awol_stmt->execute();
        $awol_data = $awol_stmt->get_result()->fetch_assoc();
        $slip['awol_deduction'] = $awol_data['awol_total'] ?? 0;
        $awol_stmt->close();

        $pdfContent = generate_salary_slip_pdf($slip, $month_name, $selected_year);
        $filename = "Salary_Slip_{$slip['employee_code']}_{$month_name}_{$selected_year}.pdf";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: no-cache, must-revalidate');
        echo $pdfContent;
        exit;
    }
}

// ─── Fetch payroll data ───────────────────────────────
$payrolls = $conn->prepare("
    SELECT p.*, e.name, e.employee_code, e.basic_salary as emp_salary
    FROM payrolls p JOIN employee e ON p.employee_id = e.id
    WHERE p.payroll_month = ? AND p.payroll_year = ?
    ORDER BY e.name ASC
");
$payrolls->bind_param('ii', $selected_month, $selected_year);
$payrolls->execute();
$payroll_data = $payrolls->get_result()->fetch_all(MYSQLI_ASSOC);
$payrolls->close();

$total_net = array_sum(array_column($payroll_data, 'net_salary'));
$emp_count = count($payroll_data);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Salary Slip</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .slip-card {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .slip-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
        }
        .slip-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1E3A8A, #4F46E5, #F59E0B);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .slip-card:hover::before {
            opacity: 1;
        }
        .stat-icon-wrapper {
            width: 3rem;
            height: 3rem;
            border-radius: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .table-row-enhanced {
            transition: all 0.2s ease;
        }
        .table-row-enhanced:hover {
            background: linear-gradient(90deg, rgba(139,92,246,0.03), rgba(217,70,239,0.02), transparent) !important;
        }
        .download-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, rgba(245,158,11,0.15), rgba(249,115,22,0.1));
            color: #F59E0B;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 2px 8px rgba(245,158,11,0.15);
        }
        .download-btn:hover {
            background: linear-gradient(135deg, rgba(245,158,11,0.25), rgba(249,115,22,0.2));
            transform: scale(1.1);
            box-shadow: 0 4px 16px rgba(245,158,11,0.25);
        }
        .summary-card {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
        }
        .summary-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            opacity: 0.08;
            transition: opacity 0.3s;
        }
        .summary-card:hover::after {
            opacity: 0.15;
        }
        .summary-card.period::after { background: #1E3A8A; }
        .summary-card.employees::after { background: #3B82F6; }
        .summary-card.total::after { background: #10B981; }
        .total-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, rgba(16,185,129,0.12), rgba(6,182,212,0.08));
            border: 1px solid rgba(16,185,129,0.2);
        }
    </style>
</head>

<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
        $page_title = "Salary Slips";
        $page_subtitle = "Generate, view and download salary slips";
        ob_start();
        ?>
        <form method="GET" class="flex items-center gap-3 glass-strong rounded-xl p-3">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-blue-500/15 flex items-center justify-center">
                    <i class="fa-regular fa-calendar text-blue-500 text-sm"></i>
                </div>
                <select name="month" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 min-w-[130px]">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-indigo-500/15 flex items-center justify-center">
                    <i class="fa-solid fa-clock-rotate-left text-indigo-500 text-sm"></i>
                </div>
                <select name="year" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30 min-w-[100px]">
                    <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-2.5 shadow-lg shadow-blue-500/25 transition-all duration-200 flex items-center gap-2 hover:scale-105 btn-ripple">
                <i class="fa-solid fa-magnifying-glass"></i> View
            </button>
        </form>
        <?php $page_actions = ob_get_clean();
        include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <div class="max-w-7xl mx-auto">

                <?php if ($emp_count > 0): ?>
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
                        <!-- Period Card -->
                        <div class="summary-card period animate-fade-in-up stagger-1">
                            <div class="flex items-center gap-4">
                                <div class="stat-icon-wrapper bg-gradient-to-br from-blue-500/20 to-indigo-500/10">
                                    <i class="fa-regular fa-calendar text-blue-500"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Period</span>
                                    <div class="text-xl font-extrabold text-white mt-0.5 truncate"><?php echo $month_name . ' ' . $selected_year; ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Employees Card -->
                        <div class="summary-card employees animate-fade-in-up stagger-2">
                            <div class="flex items-center gap-4">
                                <div class="stat-icon-wrapper bg-gradient-to-br from-blue-500/20 to-cyan-500/10">
                                    <i class="fa-solid fa-users text-blue-500"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Employees</span>
                                    <div class="text-xl font-extrabold text-white mt-0.5"><?php echo $emp_count; ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Net Pay Card -->
                        <div class="summary-card total animate-fade-in-up stagger-3">
                            <div class="flex items-center gap-4">
                                <div class="stat-icon-wrapper bg-gradient-to-br from-emerald-500/20 to-teal-500/10">
                                    <i class="fa-solid fa-sack-dollar text-emerald-500"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Net Pay</span>
                                    <div class="text-xl font-extrabold text-emerald-400 mt-0.5">$<?php echo number_format($total_net, 2); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Salary Slips Table -->
                    <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up stagger-4">
                        <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                                    <i class="fa-solid fa-file-invoice text-blue-500"></i>
                                </div>
                                <div>
                                    <h2 class="text-lg font-bold text-white">Employee Salary Slips</h2>
                                    <p class="text-xs text-zinc-500 mt-0.5"><?php echo $emp_count; ?> employees processed</p>
                                </div>
                            </div>
                            <div class="total-badge">
                                <i class="fa-solid fa-coins text-emerald-500 text-sm"></i>
                                <span class="text-sm font-bold text-emerald-400">$<?php echo number_format($total_net, 2); ?></span>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm whitespace-nowrap">
                                <thead class="text-white text-xs font-bold uppercase tracking-wider">
                                    <tr>
                                        <th class="px-6 py-4">Employee</th>
                                        <th class="px-4 py-4 text-center" title="Present"><span class="text-emerald-400">Prs</span></th>
                                        <th class="px-4 py-4 text-center" title="Half Days"><span class="text-teal-400">Hlf</span></th>
                                        <th class="px-4 py-4 text-center" title="Late"><span class="text-amber-400">Lat</span></th>
                                        <th class="px-4 py-4 text-center" title="Absent"><span class="text-red-400">Abs</span></th>
                                        <th class="px-4 py-4 text-center" title="OT Hours"><span class="text-purple-400">OT</span></th>
                                        <th class="px-6 py-4 text-right">Basic</th>
                                        <th class="px-6 py-4 text-right">OT</th>
                                        <th class="px-6 py-4 text-right">Bonus</th>
                                        <th class="px-6 py-4 text-right">Deduction</th>
                                        <th class="px-6 py-4 text-right">Net</th>
                                        <th class="px-6 py-4 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/[0.06]">
                                    <?php foreach ($payroll_data as $idx => $p): ?>
                                        <tr class="table-row-enhanced animate-fade-in-up" style="animation-delay: <?php echo 0.05 + ($idx * 0.03); ?>s;">
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-500 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-blue-500/20 shrink-0">
                                                        <?php echo strtoupper(substr($p['name'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="font-semibold text-white"><?php echo htmlspecialchars($p['name']); ?></div>
                                                        <div class="text-[11px] text-zinc-500 font-medium"><?php echo htmlspecialchars($p['employee_code']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-emerald-400"><?php echo $p['present_days'] ?? 0; ?></span></td>
                                            <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-teal-400"><?php echo $p['half_days'] ?? 0; ?></span></td>
                                            <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-amber-400"><?php echo $p['late_days'] ?? 0; ?></span></td>
                                            <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-red-400"><?php echo $p['absent_days'] ?? 0; ?></span></td>
                                            <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-purple-400"><?php echo number_format($p['overtime_hours'] ?? 0, 1); ?>h</span></td>
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
                                            <td class="px-6 py-4 text-right">
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20 font-mono text-emerald-400 font-bold">
                                                    <i class="fa-solid fa-dollar-sign text-[10px]"></i><?php echo number_format($p['net_salary'], 2); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <a href="?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>&download_pdf=1&pid=<?php echo $p['id']; ?>" title="Download PDF" class="download-btn">
                                                    <i class="fa-solid fa-file-pdf"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- Table Footer -->
                        <div class="px-6 py-4 border-t border-white/[0.06] flex items-center justify-between">
                            <span class="text-xs text-zinc-500">Showing <?php echo $emp_count; ?> employee<?php echo $emp_count > 1 ? 's' : ''; ?></span>
                            <div class="flex items-center gap-2 text-xs text-zinc-500">
                                <i class="fa-solid fa-circle-info text-blue-400"></i>
                                <span>Click PDF icon to download salary slip</span>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="glass-strong rounded-2xl p-16 text-center animate-fade-in-up">
                        <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-500/10 to-indigo-500/10 flex items-center justify-center mx-auto mb-5">
                            <i class="fa-solid fa-file-invoice text-3xl text-zinc-500"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2">No Payroll Records</h3>
                        <p class="text-zinc-400 font-medium max-w-sm mx-auto">No payroll records found for <?php echo $month_name . ' ' . $selected_year; ?>.</p>
                        <p class="text-zinc-500 text-sm mt-3">Run payroll first from the <a href="payroll.php" class="text-blue-400 hover:text-blue-300 font-semibold transition-colors underline underline-offset-2">Payroll</a> page.</p>
                        <div class="mt-6">
                            <a href="payroll.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-sm font-semibold shadow-lg shadow-blue-500/25 hover:shadow-xl hover:shadow-blue-500/35 transition-all duration-200 hover:scale-105">
                                <i class="fa-solid fa-play"></i> Generate Payroll
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
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