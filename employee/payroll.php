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

$totals = ['basic' => 0, 'ot' => 0, 'bonus' => 0, 'ded' => 0, 'gross' => 0, 'net' => 0, 'allowance' => 0, 'tax' => 0, 'leave_ded' => 0];
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
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php $sidebar_role = 'employee'; include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "My Payroll";
            $page_subtitle = "View salary breakdowns and payslip history.";
            ob_start();
        ?>
        <form method="GET" class="flex items-center gap-2">
            <select name="year" class="bg-white/[0.06] border-white/10 text-white text-sm rounded-lg p-2.5" onchange="this.form.submit()">
                <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto page-content w-full">

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-1">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Basic Salary</span>
                    <p class="text-2xl font-bold text-white mt-1">$<?php echo number_format($emp_info['basic_salary'] ?? 0, 2); ?></p>
                </div>
                <div class="glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Allowance</span>
                    <p class="text-2xl font-bold text-amber-400 mt-1">$<?php echo number_format($emp_info['allowance'] ?? 0, 2); ?></p>
                </div>
                <div class="glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-3">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Year to Date</span>
                    <p class="text-2xl font-bold text-emerald-400 mt-1">$<?php echo number_format($totals['net'], 2); ?></p>
                </div>
                <div class="glass-strong rounded-2xl p-5 card-hover animate-fade-in-up stagger-4">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">YTD Gross</span>
                    <p class="text-2xl font-bold text-violet-400 mt-1">$<?php echo number_format($totals['gross'], 2); ?></p>
                </div>
            </div>

            <?php if ($latest_payroll): ?>
            <div class="bg-gradient-to-br from-violet-600 via-fuchsia-600 to-amber-600 rounded-2xl p-6 mb-6 shadow-xl card-hover animate-fade-in-up stagger-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-white"><i class="fa-solid fa-file-invoice mr-2"></i>Latest Payslip</h3>
                    <span class="text-sm text-white/70"><?php echo date('F', mktime(0,0,0,$latest_payroll['payroll_month'],1)); ?> <?php echo $latest_payroll['payroll_year']; ?></span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div><span class="text-xs text-white/60">Basic</span><p class="text-lg font-bold text-white">$<?php echo number_format($latest_payroll['basic_salary'], 2); ?></p></div>
                    <div><span class="text-xs text-white/60">OT</span><p class="text-lg font-bold text-white">$<?php echo number_format($latest_payroll['ot_amount'] ?? 0, 2); ?></p></div>
                    <div><span class="text-xs text-white/60">Allowance</span><p class="text-lg font-bold text-white">$<?php echo number_format($latest_payroll['allowance_amount'] ?? 0, 2); ?></p></div>
                    <div><span class="text-xs text-white/60">Bonus</span><p class="text-lg font-bold text-white">$<?php echo number_format($latest_payroll['bonus_amount'] ?? 0, 2); ?></p></div>
                    <div><span class="text-xs text-white/60">Deductions</span><p class="text-lg font-bold text-rose-300">-$<?php echo number_format($latest_payroll['deduction_amount'] ?? 0, 2); ?></p></div>
                    <div><span class="text-xs text-white/60">Leave Ded.</span><p class="text-lg font-bold text-rose-300">-$<?php echo number_format($latest_payroll['leave_deduction'] ?? 0, 2); ?></p></div>
                    <div><span class="text-xs text-white/60">Tax</span><p class="text-lg font-bold text-rose-300">-$<?php echo number_format($latest_payroll['tax_amount'] ?? 0, 2); ?></p></div>
                    <div class="bg-white/20 rounded-xl p-2 text-center">
                        <span class="text-xs text-white/80">Net Pay</span>
                        <p class="text-2xl font-bold text-white">$<?php echo number_format($latest_payroll['net_salary'], 2); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($annual_data): ?>
            <div class="glass-strong rounded-2xl p-5 mb-6 card-hover animate-fade-in-up stagger-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-white"><i class="fa-solid fa-chart-line text-emerald-400 mr-2"></i>Annual Summary <?php echo $selected_year; ?></h3>
                    <span class="text-2xl font-bold text-violet-400">$<?php echo number_format($annual_data['net_annual_salary'], 2); ?></span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
                    <div><span class="text-xs text-zinc-500">Total Salary</span><p class="font-bold text-white">$<?php echo number_format($annual_data['total_salary'], 2); ?></p></div>
                    <div><span class="text-xs text-zinc-500">OT</span><p class="font-bold text-amber-400">+$<?php echo number_format($annual_data['total_ot'], 2); ?></p></div>
                    <div><span class="text-xs text-zinc-500">Bonuses</span><p class="font-bold text-emerald-400">+$<?php echo number_format($annual_data['total_bonus'], 2); ?></p></div>
                    <div><span class="text-xs text-zinc-500">Deductions</span><p class="font-bold text-rose-400">-$<?php echo number_format($annual_data['total_deduction'], 2); ?></p></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Salary Slip Inbox -->
            <?php
            $emp_email = $emp_info['email'] ?? '';
            $empEmails = [];
            if (!empty($emp_email)) {
                $mailDir = __DIR__ . '/../storage/emails';
                if (is_dir($mailDir)) {
                    $files = glob($mailDir . '/*.html');
                    foreach ($files as $file) {
                        $content = file_get_contents($file);
                        if (stripos($content, $emp_email) !== false) {
                            $filename = basename($file);
                            preg_match('/<!-- SUBJECT: (.+?) -->/', $content, $subMatch);
                            preg_match('/<!-- DATE: (.+?) -->/', $content, $dateMatch);

                            $pdfFile = str_replace('.html', '_slip.pdf', $file);
                            $empEmails[] = [
                                'filename' => $filename,
                                'subject' => $subMatch[1] ?? 'Salary Slip',
                                'date' => $dateMatch[1] ?? '',
                                'has_pdf' => file_exists($pdfFile),
                            ];
                        }
                    }
                    usort($empEmails, fn($a, $b) => strcmp($b['date'], $a['date']));
                }
            }
            ?>
            <?php if (!empty($empEmails)): ?>
            <div class="glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in-up stagger-4 mb-6" x-data="{ open: false }">
                <button @click="open = !open" class="w-full p-5 border-b border-white/[0.06] flex items-center justify-between text-left">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-emerald-500/20 flex items-center justify-center">
                            <i class="fa-solid fa-envelope text-emerald-400 text-sm"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-white">Salary Slip Inbox</h3>
                            <p class="text-xs text-zinc-500"><?php echo count($empEmails); ?> salary slip(s) received</p>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-down text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                </button>
                <div x-show="open" x-transition class="divide-y divide-white/[0.06]">
                    <?php foreach ($empEmails as $email): ?>
                    <div class="p-4 flex items-center justify-between hover:bg-white/[0.02] transition">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-violet-500/20 flex items-center justify-center shrink-0">
                                <i class="fa-solid fa-file-invoice text-violet-400 text-xs"></i>
                            </div>
                            <div>
                                <div class="text-sm font-medium text-white"><?php echo htmlspecialchars($email['subject']); ?></div>
                                <div class="text-[11px] text-zinc-500"><?php echo $email['date'] ? date('M d, Y H:i', strtotime($email['date'])) : ''; ?></div>
                            </div>
                        </div>
                        <?php if ($email['has_pdf']): ?>
                        <a href="../admin/view_email.php?file=<?php echo urlencode($email['filename']); ?>&action=pdf"
                           class="text-xs bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-semibold px-3 py-1.5 rounded-lg transition flex items-center gap-1">
                            <i class="fa-solid fa-download"></i> Download
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in-up stagger-5">
                <div class="p-5 border-b border-white/[0.06] flex items-center justify-between">
                    <h3 class="font-bold text-white"><i class="fa-solid fa-clock-rotate-left text-violet-400 mr-2"></i>Payroll History</h3>
                    <span class="text-xs text-zinc-500"><?php echo count($payroll_data); ?> records</span>
                </div>
                <?php if (empty($payroll_data)): ?>
                    <div class="p-8 text-center text-zinc-500"><p>No payroll records found for <?php echo $selected_year; ?>.</p></div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-5 py-3">Period</th>
                                <th class="px-5 py-3 text-right">Basic</th>
                                <th class="px-5 py-3 text-right">OT</th>
                                <th class="px-5 py-3 text-right">Bonus</th>
                                <th class="px-5 py-3 text-right">Deductions</th>
                                <th class="px-5 py-3 text-right">Gross</th>
                                <th class="px-5 py-3 text-right font-bold">Net</th>
                                <th class="px-5 py-3 text-center">Slip</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php foreach ($payroll_data as $p): ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="px-5 py-3 font-medium text-white"><?php echo date('F', mktime(0,0,0,$p['payroll_month'],1)); ?> <?php echo $p['payroll_year']; ?></td>
                                <td class="px-5 py-3 text-right font-mono">$<?php echo number_format($p['basic_salary'], 2); ?></td>
                                <td class="px-5 py-3 text-right font-mono text-amber-400">+$<?php echo number_format($p['ot_amount'] ?? 0, 2); ?></td>
                                <td class="px-5 py-3 text-right font-mono text-emerald-400">+$<?php echo number_format($p['bonus_amount'] ?? 0, 2); ?></td>
                                <td class="px-5 py-3 text-right font-mono text-rose-400">-$<?php echo number_format($p['deduction_amount'] ?? 0, 2); ?></td>
                                <td class="px-5 py-3 text-right font-mono text-white">$<?php echo number_format($p['gross_salary'], 2); ?></td>
                                <td class="px-5 py-3 text-right font-bold font-mono text-violet-400">$<?php echo number_format($p['net_salary'], 2); ?></td>
                                <td class="px-5 py-3 text-center">
                                    <a href="download_slip.php?pid=<?php echo $p['id']; ?>" title="Download Salary Slip PDF" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/20 text-amber-400 hover:bg-amber-500/40 transition">
                                        <i class="fa-solid fa-file-pdf text-sm"></i>
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
