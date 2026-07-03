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
    $emp_query = $conn->query("SELECT id, basic_salary FROM employee WHERE status = 'active'");
    $working_days = get_working_days_in_month($selected_year, $selected_month);

    // Get company policies
    $working_hours_per_day = (float)get_company_policy($conn, 'payroll_working_hours_per_day', 8);
    $standard_monthly_hours = $working_days * $working_hours_per_day;
    $inserted = 0;

    while ($emp = $emp_query->fetch_assoc()) {
        $eid = $emp['id'];
        $basic = (float)($emp['basic_salary'] ?: 0);
        $hourly_rate = $standard_monthly_hours > 0 ? $basic / $standard_monthly_hours : 0;

        // ── Attendance-based calculation ──
        $att_summary = get_attendance_summary_for_payroll($conn, $eid, $month_start, $month_end);
        $present_days = (int)($att_summary['present_days'] ?? 0);
        $absent_days = (int)($att_summary['absent_days'] ?? 0);
        $late_days = (int)($att_summary['late_days'] ?? 0);
        $leave_days = (int)($att_summary['leave_days'] ?? 0);

        // Calculate salary based on present days
        $daily_rate = $working_days > 0 ? $basic / $working_days : 0;

        // Late penalty: deduct 1 hour per late occurrence (configurable)
        $late_hours_penalty = $late_days * 1; // 1 hour penalty per late
        $late_deduction = $hourly_rate * $late_hours_penalty;

        // Absent deduction: full day salary per absent day
        $absent_deduction = $daily_rate * $absent_days;

        // Paid leave - full salary, no deduction
        // Unpaid leave (if any) would deduct salary

        $adjusted_basic = $basic - $late_deduction - $absent_deduction;

        // ── Overtime calculation ──
        $ot_amount = calculate_overtime_amount_for_payroll($conn, $eid, $month_start, $month_end, $hourly_rate);

        // ── Bonuses and deductions ──
        $bonus_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM bonuses WHERE employee_id = ? AND bonus_date BETWEEN ? AND ?");
        $bonus_stmt->bind_param('iss', $eid, $month_start, $month_end);
        $bonus_stmt->execute();
        $bonus_row = $bonus_stmt->get_result()->fetch_assoc();
        $bonus_amount = (float)$bonus_row['total'];
        $bonus_stmt->close();

        $ded_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM deductions WHERE employee_id = ? AND deduction_date BETWEEN ? AND ?");
        $ded_stmt->bind_param('iss', $eid, $month_start, $month_end);
        $ded_stmt->execute();
        $ded_row = $ded_stmt->get_result()->fetch_assoc();
        $deduction_amount = (float)$ded_row['total'];
        $ded_stmt->close();

        $gross = $adjusted_basic + $ot_amount + $bonus_amount;
        $net = $gross - $deduction_amount;

        // Upsert into payrolls
        $upsert = $conn->prepare("INSERT INTO payrolls (employee_id, payroll_month, payroll_year, basic_salary, ot_amount, bonus_amount, deduction_amount, gross_salary, net_salary, generated_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary), ot_amount=VALUES(ot_amount), bonus_amount=VALUES(bonus_amount),
            deduction_amount=VALUES(deduction_amount), gross_salary=VALUES(gross_salary), net_salary=VALUES(net_salary), generated_date=CURDATE()");
        $upsert->bind_param('iiidddddd', $eid, $selected_month, $selected_year, $adjusted_basic, $ot_amount, $bonus_amount, $deduction_amount, $gross, $net);
        $upsert->execute();
        $upsert->close();

        // Insert payroll details for audit trail
        $payroll_id = $conn->insert_id;
        if ($payroll_id > 0) {
            $detail_stmt = $conn->prepare("INSERT IGNORE INTO payroll_details (payroll_id, component_type, component_name, amount) VALUES (?, ?, ?, ?)");

            $components = [
                ['earning', 'Basic Salary', $adjusted_basic],
                ['earning', 'Overtime Pay', $ot_amount],
                ['earning', 'Bonuses', $bonus_amount],
                ['deduction', 'Late Deduction', $late_deduction],
                ['deduction', 'Absent Deduction', $absent_deduction],
                ['deduction', 'Other Deductions', $deduction_amount],
            ];

            foreach ($components as $comp) {
                if ($comp[2] > 0) {
                    $detail_stmt->bind_param('issd', $payroll_id, $comp[0], $comp[1], $comp[2]);
                    $detail_stmt->execute();
                }
            }
            $detail_stmt->close();
        }

        $inserted++;
    }
    $emp_query->close();
    $message = "Payroll calculated for $inserted employees. (Working days: $working_days)";
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
$emp_count = count($payroll_data);
foreach ($payroll_data as $p) {
    $total_net += $p['net_salary'];
    $total_ot += $p['ot_amount'];
    $total_bonus += $p['bonus_amount'];
    $total_ded += $p['deduction_amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Payroll Processing</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Payroll Processing"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
                <div class="animate-fade-in-up">
                    <h1 class="text-2xl font-bold text-body tracking-tight">Payroll Processing</h1>
                    <p class="text-sm text-body-secondary mt-1">Integrated with Attendance, Leave, and Overtime data. Uses MMT timezone.</p>
                </div>
                <form method="POST" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
                    <select name="pay_month" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="pay_year" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                        <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass"></i> View
                    </button>
                    <button type="submit" name="run_payroll" value="1" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                        <i class="fa-solid fa-bolt"></i> Run Payroll
                    </button>
                </form>
            </header>

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Net Payout</span>
                        <span class="p-2 bg-gradient-to-br from-emerald-500/20 to-green-500/20 text-emerald-400 rounded-lg text-sm"><i class="fa-solid fa-dollar-sign"></i></span>
                    </div>
                    <p class="text-2xl font-bold text-white">$<?php echo number_format($total_net, 2); ?></p>
                    <p class="text-xs text-zinc-500 mt-1"><?php echo $emp_count; ?> employees</p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Overtime Disbursed</span>
                        <span class="p-2 bg-gradient-to-br from-amber-500/20 to-orange-500/20 text-amber-400 rounded-lg text-sm"><i class="fa-solid fa-clock"></i></span>
                    </div>
                    <p class="text-2xl font-bold text-white">$<?php echo number_format($total_ot, 2); ?></p>
                    <p class="text-xs text-zinc-500 mt-1"><?php echo $month_name; ?> <?php echo $selected_year; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Bonuses Applied</span>
                        <span class="p-2 bg-gradient-to-br from-indigo-500/20 to-violet-500/20 text-indigo-400 rounded-lg text-sm"><i class="fa-solid fa-gift"></i></span>
                    </div>
                    <p class="text-2xl font-bold text-white">$<?php echo number_format($total_bonus, 2); ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Deductions Withheld</span>
                        <span class="p-2 bg-gradient-to-br from-rose-500/20 to-pink-500/20 text-rose-400 rounded-lg text-sm"><i class="fa-solid fa-chart-line"></i></span>
                    </div>
                    <p class="text-2xl font-bold text-white">$<?php echo number_format($total_ded, 2); ?></p>
                </div>
            </section>

            <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-file-invoice-dollar text-violet-400 mr-2"></i>Salary Sheets - <?php echo $month_name . ' ' . $selected_year; ?></h2>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo $emp_count; ?> Employees</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4 text-right">Base Salary</th>
                                <th class="px-6 py-4 text-right">OT Amount</th>
                                <th class="px-6 py-4 text-right">Bonuses</th>
                                <th class="px-6 py-4 text-right">Deductions</th>
                                <th class="px-6 py-4 text-right font-semibold text-white">Gross Salary</th>
                                <th class="px-6 py-4 text-right font-semibold text-white">Net Salary</th>
                                <th class="px-6 py-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($payroll_data)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-zinc-500">
                                    <p class="text-lg mb-2">No payroll data for <?php echo $month_name . ' ' . $selected_year; ?></p>
                                    <p class="text-sm">Click <strong>"Run Payroll"</strong> to calculate salaries with attendance & leave integration.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($payroll_data as $p): ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-white"><?php echo htmlspecialchars($p['name']); ?></div>
                                        <div class="text-xs text-zinc-500"><?php echo htmlspecialchars($p['employee_code']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-right font-mono">$<?php echo number_format($p['basic_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-amber-400 font-mono">+$<?php echo number_format($p['ot_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-emerald-400 font-mono">+$<?php echo number_format($p['bonus_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-rose-400 font-mono">-$<?php echo number_format($p['deduction_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right font-bold text-white font-mono">$<?php echo number_format($p['gross_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right font-bold text-violet-400 font-mono">$<?php echo number_format($p['net_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-emerald-500/20 text-emerald-400">Calculated</span>
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
            <span>&copy; <?php echo date('Y'); ?> ENTERPRISE HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>
