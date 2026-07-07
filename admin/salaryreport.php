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
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Salary Report";
            $page_subtitle = "Monthly salary breakdown per employee.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <select name="pay_month" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                <?php endfor; ?>
            </select>
            <select name="pay_year" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> View
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Net Payout</span>
                    <p class="text-2xl font-bold text-white">$<?php echo number_format($total_net, 2); ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Gross</span>
                    <p class="text-2xl font-bold text-white">$<?php echo number_format($total_gross, 2); ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Overtime</span>
                    <p class="text-2xl font-bold text-amber-400">$<?php echo number_format($total_ot, 2); ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Bonuses</span>
                    <p class="text-2xl font-bold text-emerald-400">$<?php echo number_format($total_bonus, 2); ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Deductions</span>
                    <p class="text-2xl font-bold text-rose-400">$<?php echo number_format($total_ded, 2); ?></p>
                </div>
            </section>

            <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-chart-column text-violet-400 mr-2"></i>Salary Details &mdash; <?php echo $month_name . ' ' . $selected_year; ?></h2>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo count($records); ?> employees</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4">Code</th>
                                <th class="px-6 py-4">Department</th>
                                <th class="px-6 py-4 text-right">Basic</th>
                                <th class="px-6 py-4 text-right">OT</th>
                                <th class="px-6 py-4 text-right">Bonus</th>
                                <th class="px-6 py-4 text-right">Deduction</th>
                                <th class="px-6 py-4 text-right">Gross</th>
                                <th class="px-6 py-4 text-right font-semibold">Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="9" class="px-6 py-12 text-center text-zinc-500">
                                    <p class="text-lg mb-2">No salary data for <?php echo $month_name . ' ' . $selected_year; ?></p>
                                    <p class="text-sm">Go to <strong>Payroll</strong> to generate salaries for this period.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($records as $r): ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-4 font-medium text-white"><?php echo htmlspecialchars($r['name']); ?></td>
                                    <td class="px-6 py-4 text-zinc-400 font-mono text-xs"><?php echo htmlspecialchars($r['employee_code']); ?></td>
                                    <td class="px-6 py-4 text-zinc-400"><?php echo htmlspecialchars($r['department_name'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-right font-mono">$<?php echo number_format($r['basic_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-amber-400 font-mono">+$<?php echo number_format($r['ot_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-emerald-400 font-mono">+$<?php echo number_format($r['bonus_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-rose-400 font-mono">-$<?php echo number_format($r['deduction_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right font-bold font-mono">$<?php echo number_format($r['gross_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right font-bold text-violet-400 font-mono">$<?php echo number_format($r['net_salary'], 2); ?></td>
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
