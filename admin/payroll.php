<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$selected_month = isset($_POST['pay_month']) ? (int)$_POST['pay_month'] : (int)mmt_date('m');
$selected_year = isset($_POST['pay_year']) ? (int)$_POST['pay_year'] : (int)mmt_date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
$message = '';
$message_type = '';

$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

// Run batch payroll calculation
if (isset($_POST['run_payroll'])) {
    $admin_id = $_SESSION['admin_id'] ?? null;
    $emp_query = $conn->query("SELECT id FROM employee WHERE status = 'active'");
    $inserted = 0;

    while ($emp = $emp_query->fetch_assoc()) {
        $eid = $emp['id'];
        $payroll_id = generate_payroll_for_employee($conn, $eid, $selected_month, $selected_year, 'Generated', $admin_id);
        if ($payroll_id) {
            $inserted++;
        }
    }
    $emp_query->close();
    $message = "Payroll calculated for $inserted employees. (Working days: " . get_working_days_in_month($selected_year, $selected_month) . ")";
    $message_type = "success";
}

// Get payroll records for selected month/year
$payrolls = $conn->prepare("
    SELECT p.*, e.name, e.employee_code, e.basic_salary as emp_salary
    FROM payrolls p
    JOIN employee e ON p.employee_id = e.id
    WHERE p.payroll_month = ? AND p.payroll_year = ?
    ORDER BY e.name ASC
");
$payrolls->bind_param('ii', $selected_month, $selected_year);
$payrolls->execute();
$payroll_data = $payrolls->get_result()->fetch_all(MYSQLI_ASSOC);
$payrolls->close();

// Summary totals
$total_net = 0;
$total_ot = 0;
$total_bonus = 0;
$total_ded = 0;
$total_allowance = 0;
$total_tax = 0;
$total_leave_ded = 0;
$emp_count = count($payroll_data);
foreach ($payroll_data as $p) {
    $total_net += $p['net_salary'];
    $total_ot += $p['ot_amount'];
    $total_bonus += $p['bonus_amount'];
    $total_ded += $p['deduction_amount'];
    $total_allowance += $p['allowance_amount'] ?? 0;
    $total_tax += $p['tax_amount'] ?? 0;
    $total_leave_ded += ($p['leave_deduction'] ?? 0) + ($p['late_deduction'] ?? 0) + ($p['unpaid_leave_deduction'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Payroll Processing</title>
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
        .payroll-stat.net::after { background: #10B981; }
        .payroll-stat.ot::after { background: #F59E0B; }
        .payroll-stat.bonus::after { background: #6366F1; }
        .payroll-stat.ded::after { background: #F43F5E; }
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
        .table-row {
            transition: all 0.2s ease;
        }
        .table-row:hover {
            background: linear-gradient(90deg, rgba(139,92,246,0.03), rgba(217,70,239,0.02), transparent) !important;
        }
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
        .status-calculated {
            background: rgba(16,185,129,0.12);
            color: #10B981;
            border: 1px solid rgba(16,185,129,0.2);
        }
        .run-payroll-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 15px rgba(16,185,129,0.25);
        }
        .run-payroll-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16,185,129,0.35);
        }
        .run-payroll-btn:active { transform: translateY(0); }
        .view-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #1E3A8A, #4F46E5);
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 15px rgba(139,92,246,0.25);
        }
        .view-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139,92,246,0.35);
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
        .amount-positive {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            color: #F59E0B;
        }
        .amount-positive::before {
            content: '+';
            font-size: 0.625rem;
            font-weight: 700;
        }
        .amount-deduction {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            color: #F43F5E;
        }
        .amount-deduction::before {
            content: '−';
            font-size: 0.75rem;
            font-weight: 700;
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
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Payroll Processing";
            $page_subtitle = "Integrated with Attendance, Leave, and Overtime data. Uses MMT timezone.";
            ob_start();
        ?>
        <form method="POST" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
        <?php echo csrf_field(); ?>
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
                    <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="view-btn">
                <i class="fa-solid fa-magnifying-glass"></i> View
            </button>
            <button type="submit" name="run_payroll" value="1" class="run-payroll-btn">
                <i class="fa-solid fa-bolt"></i> Run Payroll
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border animate-fade-in-down <?php echo $message_type == 'success' ? 'bg-emerald-500/15 border-emerald-500/25' : 'bg-red-500/15 border-red-500/25'; ?>">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl <?php echo $message_type == 'success' ? 'bg-emerald-500/20' : 'bg-red-500/20'; ?> flex items-center justify-center">
                            <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-exclamation text-red-500'; ?> text-lg"></i>
                        </div>
                        <div>
                            <p class="font-semibold <?php echo $message_type == 'success' ? 'text-emerald-400' : 'text-red-400'; ?>"><?php echo htmlspecialchars($message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
                <!-- Total Net Payout -->
                <div class="payroll-stat net animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-emerald-500/20 to-teal-500/10">
                            <i class="fa-solid fa-dollar-sign text-emerald-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Net Payout</span>
                            <p class="text-xl font-extrabold text-emerald-400 mt-0.5 truncate">$<?php echo number_format($total_net, 2); ?></p>
                            <p class="text-[10px] text-zinc-500 mt-0.5"><?php echo $emp_count; ?> employees</p>
                        </div>
                    </div>
                </div>

                <!-- Overtime Disbursed -->
                <div class="payroll-stat ot animate-fade-in-up stagger-2">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-amber-500/20 to-orange-500/10">
                            <i class="fa-solid fa-clock text-amber-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Overtime Disbursed</span>
                            <p class="text-xl font-extrabold text-amber-400 mt-0.5 truncate">$<?php echo number_format($total_ot, 2); ?></p>
                            <p class="text-[10px] text-zinc-500 mt-0.5"><?php echo $month_name; ?> <?php echo $selected_year; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Bonuses Applied -->
                <div class="payroll-stat bonus animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-indigo-500/20 to-blue-500/10">
                            <i class="fa-solid fa-gift text-indigo-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Bonuses Applied</span>
                            <p class="text-xl font-extrabold text-indigo-400 mt-0.5 truncate">$<?php echo number_format($total_bonus, 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Deductions Withheld -->
                <div class="payroll-stat ded animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-rose-500/20 to-pink-500/10">
                            <i class="fa-solid fa-chart-line text-rose-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Deductions Withheld</span>
                            <p class="text-xl font-extrabold text-rose-400 mt-0.5 truncate">$<?php echo number_format($total_ded, 2); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Salary Sheet -->
            <section class="sheet-card animate-fade-in-up stagger-5">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-file-invoice-dollar text-blue-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Salary Sheets</h2>
                            <p class="text-xs text-zinc-500 mt-0.5"><?php echo $month_name . ' ' . $selected_year; ?></p>
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20">
                        <i class="fa-solid fa-users text-indigo-400 text-xs"></i>
                        <span class="text-xs font-semibold text-indigo-400"><?php echo $emp_count; ?> Employees</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-4 py-4 text-center" title="Present Days"><i class="fa-solid fa-calendar-check text-emerald-400"></i></th>
                                <th class="px-4 py-4 text-center" title="Half Days"><i class="fa-solid fa-clock text-teal-400"></i></th>
                                <th class="px-4 py-4 text-center" title="Late Days"><i class="fa-solid fa-hourglass-half text-amber-400"></i></th>
                                <th class="px-4 py-4 text-center" title="Absent Days"><i class="fa-solid fa-calendar-xmark text-red-400"></i></th>
                                <th class="px-4 py-4 text-center" title="Leave Days"><i class="fa-solid fa-plane-departure text-blue-400"></i></th>
                                <th class="px-4 py-4 text-center" title="OT Hours"><i class="fa-solid fa-stopwatch text-purple-400"></i></th>
                                <th class="px-6 py-4 text-right">Basic</th>
                                <th class="px-6 py-4 text-right">Allow.</th>
                                <th class="px-6 py-4 text-right">OT</th>
                                <th class="px-6 py-4 text-right">Bonus</th>
                                <th class="px-6 py-4 text-right">Ded.</th>
                                <th class="px-6 py-4 text-right">Tax</th>
                                <th class="px-6 py-4 text-right font-bold">Net</th>
                                <th class="px-6 py-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($payroll_data)): ?>
                            <tr>
                                <td colspan="15" class="px-6 py-16 text-center">
                                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500/10 to-indigo-500/10 flex items-center justify-center mx-auto mb-4">
                                        <i class="fa-solid fa-file-invoice-dollar text-2xl text-zinc-500"></i>
                                    </div>
                                    <p class="text-zinc-400 font-medium">No payroll data for <?php echo $month_name . ' ' . $selected_year; ?></p>
                                    <p class="text-zinc-500 text-sm mt-2">Click <strong class="text-emerald-400">"Run Payroll"</strong> to calculate salaries with attendance & leave integration.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php
                                // Fetch deduction details for each employee
                                $ded_details = [];
                                foreach ($payroll_data as $p) {
                                    $emp_id = $p['employee_id'];
                                    $ded_stmt = $conn->prepare("SELECT d.title, d.amount, d.deduction_date, dt.deduction_name FROM deductions d LEFT JOIN deduction_types dt ON d.deduction_type_id = dt.id WHERE d.employee_id = ? AND d.deduction_date BETWEEN ? AND ?");
                                    $ded_stmt->bind_param('iss', $emp_id, $month_start, $month_end);
                                    $ded_stmt->execute();
                                    $ded_details[$emp_id] = $ded_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    $ded_stmt->close();
                                }
                                ?>
                                <?php foreach ($payroll_data as $idx => $p): ?>
                                <tr class="table-row animate-fade-in-up" style="animation-delay: <?php echo 0.05 + ($idx * 0.03); ?>s;">
                                    <td class="px-6 py-4">
                                        <div class="employee-cell">
                                            <div class="employee-avatar">
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
                                    <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-blue-400"><?php echo ($p['paid_leave_days'] ?? 0) + ($p['unpaid_leave_days'] ?? 0); ?></span></td>
                                    <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-purple-400"><?php echo number_format($p['overtime_hours'] ?? 0, 1); ?>h</span></td>
                                    <td class="px-6 py-4 text-right font-mono text-white font-medium">$<?php echo number_format($p['basic_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if (($p['allowance_amount'] ?? 0) > 0): ?>
                                            <span class="amount-positive font-mono font-medium">$<?php echo number_format($p['allowance_amount'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if ($p['ot_amount'] > 0): ?>
                                            <span class="amount-positive font-mono font-medium">$<?php echo number_format($p['ot_amount'], 2); ?></span>
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
                                    <td class="px-6 py-4 text-right relative group">
                                        <?php if ($p['deduction_amount'] > 0): ?>
                                            <span class="amount-deduction font-mono font-medium cursor-help">$<?php echo number_format($p['deduction_amount'], 2); ?></span>
                                            <?php if (!empty($ded_details[$p['employee_id']])): ?>
                                            <div class="absolute right-0 top-full mt-2 w-64 bg-slate-800 border border-white/10 rounded-xl shadow-2xl p-3 z-50 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200">
                                                <div class="text-xs font-bold text-zinc-400 mb-2 uppercase tracking-wider">Deduction Breakdown</div>
                                                <?php foreach ($ded_details[$p['employee_id']] as $dd): ?>
                                                <div class="flex items-center justify-between py-1.5 border-b border-white/5 last:border-0">
                                                    <div>
                                                        <div class="text-xs text-white font-medium"><?php echo htmlspecialchars($dd['title'] ?? $dd['deduction_name'] ?? '-'); ?></div>
                                                        <div class="text-[10px] text-zinc-500"><?php echo date('M d', strtotime($dd['deduction_date'])); ?></div>
                                                    </div>
                                                    <span class="text-xs font-mono text-rose-400 font-semibold">-$<?php echo number_format($dd['amount'], 2); ?></span>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if (($p['tax_amount'] ?? 0) > 0): ?>
                                            <span class="amount-deduction font-mono font-medium">$<?php echo number_format($p['tax_amount'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <span class="net-highlight">$<?php echo number_format($p['net_salary'], 2); ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold border <?php echo get_payroll_status_badge($p['status'] ?? 'Generated'); ?>">
                                            <i class="fa-solid <?php echo get_payroll_status_icon($p['status'] ?? 'Generated'); ?> text-[9px]"></i>
                                            <?php echo $p['status'] ?? 'Generated'; ?>
                                        </span>
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
