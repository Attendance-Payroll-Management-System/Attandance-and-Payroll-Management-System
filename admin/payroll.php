<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

// Ensure new payroll table exists
if (!payroll_table_exists($conn)) {
    // Auto-create if not exists
    $sql = file_get_contents(__DIR__ . '/../config/migration_new_payroll_table.sql');
    $conn->multi_query($sql);
    while ($conn->next_result()) { $conn->store_result(); }
}

$selected_month = isset($_POST['pay_month']) ? (int)$_POST['pay_month'] : (int)mmt_date('m');
$selected_year = isset($_POST['pay_year']) ? (int)$_POST['pay_year'] : (int)mmt_date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
$message = '';
$message_type = '';
$currency = get_currency($conn);

$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

// Run batch payroll calculation
if (isset($_POST['run_payroll'])) {
    [$inserted, $working_days] = generate_batch_new_payroll($conn, $selected_month, $selected_year);
    $message = "Payroll calculated for $inserted employees. (Working days: $working_days)";
    $message_type = "success";
}

// Handle single payroll recalculation
if (isset($_POST['recalc_payroll']) && isset($_POST['recalc_id'])) {
    $recalc_id = (int)$_POST['recalc_id'];
    $detail = get_new_payroll_detail($conn, $recalc_id);
    if ($detail) {
        $new_pid = generate_new_payroll($conn, $detail['employee_id'], $detail['pay_month'], $detail['pay_year']);
        if ($new_pid) {
            $message = "Payroll recalculated for " . htmlspecialchars($detail['name']) . ".";
            $message_type = "success";
        }
    }
}

// Handle status update (mark as Paid / Cancelled)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!validate_csrf_token()) {
        $message = 'Invalid CSRF token.';
        $message_type = 'error';
    } else {
        $pid = (int)$_POST['payroll_id'];
        $new_status = $_POST['new_status'];
        $remarks = trim($_POST['remarks'] ?? '');
        if (update_new_payroll_status($conn, $pid, $new_status, $remarks ?: null)) {
            $message = "Payroll status updated to $new_status.";
            $message_type = "success";
        }
    }
}

// Get payroll records for selected month/year
$payrolls = $conn->prepare("
    SELECT p.*, e.name, e.employee_code, e.basic_salary as emp_salary
    FROM payroll p
    JOIN employee e ON p.employee_id = e.id
    WHERE p.pay_month = ? AND p.pay_year = ?
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
$total_leave_ded = 0;
$total_late_ded = 0;
$total_absent_ded = 0;
$emp_count = count($payroll_data);
foreach ($payroll_data as $p) {
    $total_net += $p['net_salary'];
    $total_ot += $p['overtime_amount'];
    $total_bonus += $p['bonus'];
    $total_ded += $p['other_deduction'];
    $total_allowance += $p['allowance'];
    $total_leave_ded += $p['leave_deduction'];
    $total_late_ded += $p['late_deduction'];
    $total_absent_ded += $p['absent_deduction'];
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
            content: '\2212';
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
            $page_subtitle = "Calculate salaries from attendance, leave, overtime, and bonus data.";
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
                            <p class="text-xl font-extrabold text-emerald-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_net, 2); ?></p>
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
                            <p class="text-xl font-extrabold text-amber-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_ot, 2); ?></p>
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
                            <p class="text-xl font-extrabold text-indigo-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_bonus, 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Total Deductions -->
                <div class="payroll-stat ded animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-rose-500/20 to-pink-500/10">
                            <i class="fa-solid fa-chart-line text-rose-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Deductions</span>
                            <p class="text-xl font-extrabold text-rose-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_leave_ded + $total_late_ded + $total_absent_ded + $total_ded, 2); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Deduction Breakdown -->
            <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="glass-strong rounded-xl p-4 text-center">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Leave Deduction</p>
                    <p class="text-lg font-bold text-rose-400 mt-1"><?php echo $currency; ?> <?php echo number_format($total_leave_ded, 2); ?></p>
                </div>
                <div class="glass-strong rounded-xl p-4 text-center">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Late Deduction</p>
                    <p class="text-lg font-bold text-amber-400 mt-1"><?php echo $currency; ?> <?php echo number_format($total_late_ded, 2); ?></p>
                </div>
                <div class="glass-strong rounded-xl p-4 text-center">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Absent Deduction</p>
                    <p class="text-lg font-bold text-red-400 mt-1"><?php echo $currency; ?> <?php echo number_format($total_absent_ded, 2); ?></p>
                </div>
                <div class="glass-strong rounded-xl p-4 text-center">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Other Deductions</p>
                    <p class="text-lg font-bold text-pink-400 mt-1"><?php echo $currency; ?> <?php echo number_format($total_ded, 2); ?></p>
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
                            <h2 class="font-bold text-white text-lg">Salary Sheet</h2>
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
                                <th class="px-3 py-4 text-center" title="Present"><i class="fa-solid fa-calendar-check text-emerald-400"></i></th>
                                <th class="px-3 py-4 text-center" title="Leave"><i class="fa-solid fa-plane-departure text-blue-400"></i></th>
                                <th class="px-3 py-4 text-center" title="Late"><i class="fa-solid fa-hourglass-half text-amber-400"></i></th>
                                <th class="px-3 py-4 text-center" title="Absent"><i class="fa-solid fa-calendar-xmark text-red-400"></i></th>
                                <th class="px-3 py-4 text-center" title="OT Hours"><i class="fa-solid fa-stopwatch text-purple-400"></i></th>
                                <th class="px-4 py-4 text-right">Basic</th>
                                <th class="px-4 py-4 text-right">Allow.</th>
                                <th class="px-4 py-4 text-right">OT</th>
                                <th class="px-4 py-4 text-right">Bonus</th>
                                <th class="px-4 py-4 text-right">Leave Ded.</th>
                                <th class="px-4 py-4 text-right">Late Ded.</th>
                                <th class="px-4 py-4 text-right">Absent Ded.</th>
                                <th class="px-4 py-4 text-right">Other Ded.</th>
                                <th class="px-5 py-4 text-right font-bold">Net</th>
                                <th class="px-4 py-4 text-center">Status</th>
                                <th class="px-4 py-4 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($payroll_data)): ?>
                            <tr>
                                <td colspan="17" class="px-6 py-16 text-center">
                                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500/10 to-indigo-500/10 flex items-center justify-center mx-auto mb-4">
                                        <i class="fa-solid fa-file-invoice-dollar text-2xl text-zinc-500"></i>
                                    </div>
                                    <p class="text-zinc-400 font-medium">No payroll data for <?php echo $month_name . ' ' . $selected_year; ?></p>
                                    <p class="text-zinc-500 text-sm mt-2">Click <strong class="text-emerald-400">"Run Payroll"</strong> to calculate salaries from attendance, leave, and overtime data.</p>
                                </td>
                            </tr>
                            <?php else: ?>
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
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-emerald-400"><?php echo $p['present_days']; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-blue-400"><?php echo $p['leave_days']; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-amber-400"><?php echo $p['late_days']; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-red-400"><?php echo $p['absent_days']; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-purple-400"><?php echo number_format($p['overtime_hours'], 1); ?>h</span></td>
                                    <td class="px-4 py-4 text-right font-mono text-white font-medium"><?php echo $currency; ?> <?php echo number_format($p['basic_salary'], 2); ?></td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['allowance'] > 0): ?>
                                            <span class="amount-positive font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($p['allowance'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['overtime_amount'] > 0): ?>
                                            <span class="amount-positive font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($p['overtime_amount'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['bonus'] > 0): ?>
                                            <span class="inline-flex items-center gap-1 font-mono text-emerald-400 font-medium">
                                                <i class="fa-solid fa-plus text-[9px]"></i><?php echo $currency; ?> <?php echo number_format($p['bonus'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['leave_deduction'] > 0): ?>
                                            <span class="amount-deduction font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($p['leave_deduction'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['late_deduction'] > 0): ?>
                                            <span class="amount-deduction font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($p['late_deduction'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['absent_deduction'] > 0): ?>
                                            <span class="amount-deduction font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($p['absent_deduction'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['other_deduction'] > 0): ?>
                                            <span class="amount-deduction font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($p['other_deduction'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <span class="net-highlight"><?php echo $currency; ?> <?php echo number_format($p['net_salary'], 2); ?></span>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <span class="status-badge <?php echo get_new_payroll_status_badge($p['payment_status']); ?>">
                                            <i class="fa-solid <?php echo get_new_payroll_status_icon($p['payment_status']); ?> text-[9px]"></i>
                                            <?php echo $p['payment_status']; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <form method="POST" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="recalc_id" value="<?php echo $p['id']; ?>">
                                                <button type="submit" name="recalc_payroll" value="1" class="w-7 h-7 rounded-lg bg-blue-500/15 text-blue-400 hover:bg-blue-500/25 flex items-center justify-center transition-colors" title="Recalculate">
                                                    <i class="fa-solid fa-rotate text-[10px]"></i>
                                                </button>
                                            </form>
                                            <a href="payroll_detail.php?id=<?php echo $p['id']; ?>" class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-400 hover:bg-indigo-500/25 flex items-center justify-center transition-colors" title="View Details">
                                                <i class="fa-solid fa-eye text-[10px]"></i>
                                            </a>
                                        </div>
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
