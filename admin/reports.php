<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
require_once '../config/db.php';
require_once '../config/helpers.php';

$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$message = '';
$message_type = '';

// Generate annual payroll from monthly payrolls
if (isset($_POST['generate_annual'])) {
    $emp_query = $conn->query("SELECT id FROM employee WHERE status = 'active'");
    $inserted = 0;

    while ($emp = $emp_query->fetch_assoc()) {
        $eid = $emp['id'];

        $agg = $conn->prepare("SELECT
            COALESCE(SUM(basic_salary), 0) as total_basic,
            COALESCE(SUM(ot_amount), 0) as total_ot,
            COALESCE(SUM(bonus_amount), 0) as total_bonus,
            COALESCE(SUM(deduction_amount), 0) as total_ded,
            COALESCE(SUM(gross_salary), 0) as total_gross,
            COALESCE(SUM(net_salary), 0) as total_net
        FROM payrolls WHERE employee_id = ? AND payroll_year = ?");
        $agg->bind_param('ii', $eid, $selected_year);
        $agg->execute();
        $row = $agg->get_result()->fetch_assoc();
        $agg->close();

        $upsert = $conn->prepare("INSERT INTO annual_payrolls (employee_id, payroll_year, total_salary, total_bonus, total_deduction, total_ot, net_annual_salary)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE total_salary=VALUES(total_salary), total_bonus=VALUES(total_bonus),
            total_deduction=VALUES(total_deduction), total_ot=VALUES(total_ot), net_annual_salary=VALUES(net_annual_salary)");
        $upsert->bind_param('iiddddd', $eid, $selected_year, $row['total_gross'], $row['total_bonus'], $row['total_ded'], $row['total_ot'], $row['total_net']);
        $upsert->execute();
        $upsert->close();
        $inserted++;
    }
    $emp_query->close();
    $message = "Annual payroll generated for $inserted employees for $selected_year.";
    $message_type = "success";
}

// Fetch annual payroll records
$records = $conn->prepare("
    SELECT a.*, e.name, e.employee_code, d.department_name
    FROM annual_payrolls a
    JOIN employee e ON a.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE a.payroll_year = ?
    ORDER BY e.name ASC
");
$records->bind_param('i', $selected_year);
$records->execute();
$annual_data = $records->get_result()->fetch_all(MYSQLI_ASSOC);
$records->close();

$total_net = 0; $total_gross = 0; $total_ot = 0; $total_bonus = 0; $total_ded = 0;
foreach ($annual_data as $r) {
    $total_net += $r['net_annual_salary'];
    $total_gross += $r['total_salary'];
    $total_ot += $r['total_ot'];
    $total_bonus += $r['total_bonus'];
    $total_ded += $r['total_deduction'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Annual Payroll Report</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Annual Payroll Report";
            $page_subtitle = "Yearly salary summary aggregated from monthly payrolls.";
            ob_start();
        ?>
        <form method="GET" class="flex items-center gap-3 glass-strong rounded-xl p-3">
            <select name="year" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> View
            </button>
        </form>
        <form method="POST">
        <?php echo csrf_field(); ?>
            <button type="submit" name="generate_annual" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-bolt"></i> Generate Annual Payroll
            </button>
        </form>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="rounded-xl bg-white/[0.06] hover:bg-white/10 text-zinc-300 font-semibold text-sm px-4 py-2.5 shadow-sm transition flex items-center gap-2 border border-white/10">
                <i class="fa-solid fa-print"></i> Print
            </button>
            <button onclick="exportCSV()" class="rounded-xl bg-white/[0.06] hover:bg-white/10 text-zinc-300 font-semibold text-sm px-4 py-2.5 shadow-sm transition flex items-center gap-2 border border-white/10">
                <i class="fa-solid fa-file-excel"></i> CSV
            </button>
        </div>
        <?php $page_actions = ob_get_clean(); ?>
        <script>
        function exportCSV() {
            const rows = document.querySelectorAll('#reportTable tbody tr');
            if (!rows.length) return;
            let csv = 'Employee,Code,Department,Total Salary,Overtime,Bonuses,Deductions,Net Annual\n';
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const data = [];
                cells.forEach((c, i) => {
                    if (i < cells.length - 1 || true) {
                        let val = c.innerText.trim().replace(/,/g, '');
                        data.push(val);
                    }
                });
                csv += data.join(',') + '\n';
            });
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'annual_payroll_<?php echo $selected_year; ?>.csv';
            a.click();
            URL.revokeObjectURL(url);
        }
        </script>
        <?php include "../includes/topbar.php"; ?>

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Annual Net</span>
                    <p class="text-2xl font-bold text-white">$<?php echo number_format($total_net, 2); ?></p>
                    <span class="text-xs text-zinc-500"><?php echo count($annual_data); ?> employees</span>
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
                    <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-file-lines text-violet-400 mr-2"></i>Annual Payroll &mdash; <?php echo $selected_year; ?></h2>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo count($annual_data); ?> employees</span>
                </div>
                <div class="overflow-x-auto">
                    <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4">Code</th>
                                <th class="px-6 py-4">Department</th>
                                <th class="px-6 py-4 text-right">Total Salary</th>
                                <th class="px-6 py-4 text-right">Overtime</th>
                                <th class="px-6 py-4 text-right">Bonuses</th>
                                <th class="px-6 py-4 text-right">Deductions</th>
                                <th class="px-6 py-4 text-right font-semibold">Net Annual</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($annual_data)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-zinc-500">
                                    <p class="text-lg mb-2">No annual payroll data for <?php echo $selected_year; ?></p>
                                    <p class="text-sm">Click <strong>"Generate Annual Payroll"</strong> to aggregate from monthly payrolls.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($annual_data as $r): ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-4 font-medium text-white"><?php echo htmlspecialchars($r['name']); ?></td>
                                    <td class="px-6 py-4 text-zinc-400 font-mono text-xs"><?php echo htmlspecialchars($r['employee_code']); ?></td>
                                    <td class="px-6 py-4 text-zinc-400"><?php echo htmlspecialchars($r['department_name'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-right font-mono">$<?php echo number_format($r['total_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-amber-400 font-mono">+$<?php echo number_format($r['total_ot'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-emerald-400 font-mono">+$<?php echo number_format($r['total_bonus'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-rose-400 font-mono">-$<?php echo number_format($r['total_deduction'], 2); ?></td>
                                    <td class="px-6 py-4 text-right font-bold text-violet-400 font-mono">$<?php echo number_format($r['net_annual_salary'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> AURA HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>
