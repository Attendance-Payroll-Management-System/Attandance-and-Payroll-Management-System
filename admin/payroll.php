<?php
require_once '../config/db.php';

$selected_month = isset($_POST['pay_month']) ? (int)$_POST['pay_month'] : (int)date('m');
$selected_year = isset($_POST['pay_year']) ? (int)$_POST['pay_year'] : (int)date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
$message = '';
$message_type = '';

$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

// Run batch payroll calculation
if (isset($_POST['run_payroll'])) {
    $emp_query = $conn->query("SELECT id, basic_salary FROM employee WHERE status = 'active'");
    $working_days = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
    $standard_hours = $working_days * 8;
    $inserted = 0;

    while ($emp = $emp_query->fetch_assoc()) {
        $eid = $emp['id'];
        $basic = $emp['basic_salary'] ?: 0;

        // Get approved OT total hours
        $ot_stmt = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as total_ot FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
        $ot_stmt->bind_param('iss', $eid, $month_start, $month_end);
        $ot_stmt->execute();
        $ot_row = $ot_stmt->get_result()->fetch_assoc();
        $total_ot_hours = $ot_row['total_ot'];
        $ot_stmt->close();

        // Calculate OT amount (hourly_rate * 1.5 * OT hours)
        $hourly_rate = $standard_hours > 0 ? $basic / $standard_hours : 0;
        $ot_amount = round($hourly_rate * 1.5 * $total_ot_hours, 2);

        // Get bonuses for the month
        $bonus_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM bonuses WHERE employee_id = ? AND bonus_date BETWEEN ? AND ?");
        $bonus_stmt->bind_param('iss', $eid, $month_start, $month_end);
        $bonus_stmt->execute();
        $bonus_row = $bonus_stmt->get_result()->fetch_assoc();
        $bonus_amount = $bonus_row['total'];
        $bonus_stmt->close();

        // Get deductions for the month
        $ded_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM deductions WHERE employee_id = ? AND deduction_date BETWEEN ? AND ?");
        $ded_stmt->bind_param('iss', $eid, $month_start, $month_end);
        $ded_stmt->execute();
        $ded_row = $ded_stmt->get_result()->fetch_assoc();
        $deduction_amount = $ded_row['total'];
        $ded_stmt->close();

        $gross = $basic + $ot_amount + $bonus_amount;
        $net = $gross - $deduction_amount;

        // Upsert into payrolls
        $upsert = $conn->prepare("INSERT INTO payrolls (employee_id, payroll_month, payroll_year, basic_salary, ot_amount, bonus_amount, deduction_amount, gross_salary, net_salary, generated_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary), ot_amount=VALUES(ot_amount), bonus_amount=VALUES(bonus_amount),
            deduction_amount=VALUES(deduction_amount), gross_salary=VALUES(gross_salary), net_salary=VALUES(net_salary), generated_date=CURDATE()");
        $upsert->bind_param('iiidddddd', $eid, $selected_month, $selected_year, $basic, $ot_amount, $bonus_amount, $deduction_amount, $gross, $net);
        $upsert->execute();
        $upsert->close();
        $inserted++;
    }
    $emp_query->close();
    $message = "Payroll calculated for $inserted employees.";
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
    <title>Payroll - HRMS Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-gray-50 font-sans antialiased">
    <div class="flex min-h-screen">
        <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col border-r border-slate-800">
            <div class="p-6 border-b border-slate-800 flex items-center gap-3">
                <div class="h-8 w-8 bg-indigo-600 rounded-lg flex items-center justify-center font-bold text-white text-lg"><i class="fa-solid fa-coins"></i></div>
                <span class="font-bold text-white text-lg tracking-wide">HRMS Core</span>
            </div>
            <nav class="flex-1 p-4 space-y-1 text-sm">
                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition"><i class="fa-solid fa-chart-pie w-5"></i> Dashboard</a>
                <a href="employee.php" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition"><i class="fa-solid fa-users w-5"></i> Employees</a>
                <a href="leaveApproval.php" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition"><i class="fa-solid fa-envelope-open-text w-5"></i> Leave Requests</a>
                <a href="overtimeApproval.php" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition"><i class="fa-solid fa-clock w-5"></i> Overtime Requests</a>
                <a href="payroll.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-indigo-600 text-white font-medium"><i class="fa-solid fa-coins w-5"></i> Payroll</a>
            </nav>
        </aside>

        <main class="flex-1 p-8 overflow-y-auto">
            <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Payroll Processing</h1>
                    <p class="text-sm text-gray-500">Calculate salaries with attendance, overtime, bonuses, and deductions.</p>
                </div>
                <form method="POST" class="flex flex-wrap items-center gap-3 bg-white p-3 rounded-xl border border-gray-200 shadow-sm">
                    <select name="pay_month" class="bg-gray-50 border border-gray-300 text-sm rounded-lg p-2.5">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="pay_year" class="bg-gray-50 border border-gray-300 text-sm rounded-lg p-2.5">
                        <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm rounded-lg px-5 py-2.5 transition">
                        <i class="fa-solid fa-magnifying-glass"></i> View
                    </button>
                    <button type="submit" name="run_payroll" value="1" class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-sm rounded-lg px-5 py-2.5 transition">
                        <i class="fa-solid fa-bolt"></i> Run Payroll
                    </button>
                </form>
            </header>

            <?php if ($message): ?>
                <div class="mb-4 px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-500">Total Net Payout</span>
                        <span class="p-2 bg-emerald-50 text-emerald-600 rounded-lg text-sm"><i class="fa-solid fa-dollar-sign"></i></span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($total_net, 2); ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $emp_count; ?> employees</p>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-500">Overtime Disbursed</span>
                        <span class="p-2 bg-amber-50 text-amber-600 rounded-lg text-sm"><i class="fa-solid fa-clock"></i></span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($total_ot, 2); ?></p>
                    <p class="text-xs text-gray-400 mt-1"><?php echo $month_name; ?> <?php echo $selected_year; ?></p>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-500">Bonuses Applied</span>
                        <span class="p-2 bg-indigo-50 text-indigo-600 rounded-lg text-sm"><i class="fa-solid fa-gift"></i></span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($total_bonus, 2); ?></p>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-500">Deductions Withheld</span>
                        <span class="p-2 bg-rose-50 text-rose-600 rounded-lg text-sm"><i class="fa-solid fa-chart-line"></i></span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($total_ded, 2); ?></p>
                </div>
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="font-bold text-gray-900 text-lg">Salary Sheets - <?php echo $month_name . ' ' . $selected_year; ?></h2>
                    <span class="px-2.5 py-1 text-xs font-semibold bg-indigo-50 text-indigo-700 rounded-full"><?php echo $emp_count; ?> Employees</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4 text-right">Base Salary</th>
                                <th class="px-6 py-4 text-right">OT Amount</th>
                                <th class="px-6 py-4 text-right">Bonuses</th>
                                <th class="px-6 py-4 text-right">Deductions</th>
                                <th class="px-6 py-4 text-right font-semibold text-gray-900">Gross Salary</th>
                                <th class="px-6 py-4 text-right font-semibold text-gray-900">Net Salary</th>
                                <th class="px-6 py-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($payroll_data)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-slate-400">
                                    <p class="text-lg mb-2">No payroll data for <?php echo $month_name . ' ' . $selected_year; ?></p>
                                    <p class="text-sm">Click <strong>"Run Payroll"</strong> to calculate salaries for this period.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($payroll_data as $p): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($p['name']); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($p['employee_code']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-right font-mono">$<?php echo number_format($p['basic_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-amber-600 font-mono">+$<?php echo number_format($p['ot_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-emerald-600 font-mono">+$<?php echo number_format($p['bonus_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right text-rose-600 font-mono">-$<?php echo number_format($p['deduction_amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right font-bold text-slate-900 font-mono">$<?php echo number_format($p['gross_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right font-bold text-indigo-700 font-mono">$<?php echo number_format($p['net_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="px-2 py-1 text-xs font-medium bg-emerald-100 text-emerald-800 rounded-full">Calculated</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
