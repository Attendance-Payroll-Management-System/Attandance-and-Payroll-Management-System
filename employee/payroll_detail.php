<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }

$employee_id = (int)$_SESSION['employee_id'];
$payroll_id = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;

if (!$payroll_id) {
    header('Location: payroll.php');
    exit;
}

// Fetch payroll — VERIFY ownership by employee_id
$stmt = $conn->prepare("SELECT * FROM payrolls WHERE id = ? AND employee_id = ?");
$stmt->bind_param("ii", $payroll_id, $employee_id);
$stmt->execute();
$payroll = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payroll) {
    $error = "Payroll record not found or access denied.";
}

$month_name = $payroll ? date('F', mktime(0, 0, 0, $payroll['payroll_month'], 1)) : '';

// Fetch payroll_details (earnings & deductions components)
$earnings = [];
$deductions = [];
if ($payroll) {
    $det_stmt = $conn->prepare("SELECT component_type, component_name, amount FROM payroll_details WHERE payroll_id = ? ORDER BY id");
    $det_stmt->bind_param("i", $payroll_id);
    $det_stmt->execute();
    $details = $det_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $det_stmt->close();

    foreach ($details as $d) {
        if ($d['component_type'] === 'earning') {
            $earnings[] = $d;
        } else {
            $deductions[] = $d;
        }
    }
}

// Employee info
$emp_info = null;
if ($payroll) {
    $emp = $conn->prepare("SELECT e.name, e.employee_code, e.basic_salary, e.email, d.department_name, p.position_name FROM employee e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN positions p ON e.position_id = p.id WHERE e.id = ?");
    $emp->bind_param("i", $employee_id);
    $emp->execute();
    $emp_info = $emp->get_result()->fetch_assoc();
    $emp->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Payroll Detail</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .glass-card {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .glass-card:hover {
            box-shadow: var(--shadow-card-hover);
        }
        .glass-card::before {
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
        .glass-card:hover::before {
            opacity: 1;
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
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        @media (min-width: 768px) {
            .info-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .info-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #71717a;
        }
        .info-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: #fff;
        }
        .attend-icon-box {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .net-gradient {
            background: linear-gradient(135deg, rgba(16,185,129,0.15), rgba(5,150,105,0.1));
            border: 1px solid rgba(16,185,129,0.25);
            border-radius: 1.25rem;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            color: #a1a1aa;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .back-btn:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
            transform: translateX(-2px);
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .action-btn:hover {
            transform: translateY(-2px);
        }
        .action-btn:active {
            transform: translateY(0);
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php
            $page_title = "Payroll Detail";
            $page_subtitle = $payroll ? ($emp_info['name'] ?? '') . " — " . $month_name . " " . $payroll['payroll_year'] : "Payroll detail view";
            ob_start();
        ?>
        <a href="payroll.php" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i> Back to Payroll History
        </a>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto page-content w-full">

            <?php if (isset($error)): ?>
                <div class="glass-card p-12 text-center animate-fade-in-up">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-rose-500/15 to-pink-500/10 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-circle-exclamation text-3xl text-rose-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">Payroll Not Found</h3>
                    <p class="text-zinc-400 text-sm mb-6"><?php echo htmlspecialchars($error); ?></p>
                    <a href="payroll.php" class="action-btn bg-gradient-to-r from-blue-500 to-indigo-500 text-white shadow-lg shadow-blue-500/25">
                        <i class="fa-solid fa-arrow-left"></i> Back to Payroll
                    </a>
                </div>
            <?php else: ?>

            <!-- Header with Status Badge -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-8 animate-fade-in-up">
                <div>
                    <h1 class="text-2xl font-extrabold text-white">Payroll Detail</h1>
                    <p class="text-sm text-zinc-400 mt-1">
                        <?php echo htmlspecialchars($emp_info['name'] ?? ''); ?> · <?php echo htmlspecialchars($emp_info['employee_code'] ?? ''); ?> · <?php echo $month_name . ' ' . $payroll['payroll_year']; ?>
                    </p>
                </div>
                <span class="status-badge <?php echo get_payroll_status_badge($payroll['status'] ?? 'Generated'); ?>">
                    <i class="fa-solid <?php echo get_payroll_status_icon($payroll['status'] ?? 'Generated'); ?> text-[10px]"></i>
                    <?php echo htmlspecialchars($payroll['status'] ?? 'Generated'); ?>
                </span>
            </div>

            <!-- Employee Info -->
            <div class="glass-card mb-6 animate-fade-in-up stagger-1">
                <div class="p-6 border-b border-white/[0.06]">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-user text-blue-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Employee Information</h2>
                            <p class="text-xs text-zinc-500 mt-0.5">Personal and role details</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp_info['name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Employee Code</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp_info['employee_code'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Department</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp_info['department_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Position</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp_info['position_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp_info['email'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payroll Info -->
            <div class="glass-card mb-6 animate-fade-in-up stagger-2">
                <div class="p-6 border-b border-white/[0.06]">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-file-invoice-dollar text-amber-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Payroll Information</h2>
                            <p class="text-xs text-zinc-500 mt-0.5">Period and basic salary details</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Pay Period</span>
                            <span class="info-value"><?php echo $month_name . ' ' . $payroll['payroll_year']; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Generated Date</span>
                            <span class="info-value"><?php echo htmlspecialchars($payroll['generated_date'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Basic Salary</span>
                            <span class="info-value font-mono">$<?php echo number_format($payroll['basic_salary'], 2); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Working Days</span>
                            <span class="info-value"><?php echo $payroll['working_days']; ?> days</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Summary -->
            <div class="glass-card mb-6 animate-fade-in-up stagger-3">
                <div class="p-6 border-b border-white/[0.06]">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-calendar-check text-emerald-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Attendance Summary</h2>
                            <p class="text-xs text-zinc-500 mt-0.5">Monthly attendance breakdown</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-4">
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/15">
                            <div class="attend-icon-box bg-emerald-500/20">
                                <i class="fa-solid fa-calendar-check text-emerald-400 text-sm"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-400">Present</span>
                                <p class="text-lg font-extrabold text-white"><?php echo $payroll['present_days']; ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-amber-500/10 border border-amber-500/15">
                            <div class="attend-icon-box bg-amber-500/20">
                                <i class="fa-solid fa-hourglass-half text-amber-400 text-sm"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-amber-400">Late</span>
                                <p class="text-lg font-extrabold text-white"><?php echo $payroll['late_days']; ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-teal-500/10 border border-teal-500/15">
                            <div class="attend-icon-box bg-teal-500/20">
                                <i class="fa-solid fa-clock text-teal-400 text-sm"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-teal-400">Half Day</span>
                                <p class="text-lg font-extrabold text-white"><?php echo $payroll['half_days']; ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-red-500/10 border border-red-500/15">
                            <div class="attend-icon-box bg-red-500/20">
                                <i class="fa-solid fa-calendar-xmark text-red-400 text-sm"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-red-400">Absent</span>
                                <p class="text-lg font-extrabold text-white"><?php echo $payroll['absent_days']; ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-sky-500/10 border border-sky-500/15">
                            <div class="attend-icon-box bg-sky-500/20">
                                <i class="fa-solid fa-plane-departure text-sky-400 text-sm"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-sky-400">Paid Leave</span>
                                <p class="text-lg font-extrabold text-white"><?php echo $payroll['paid_leave_days']; ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-orange-500/10 border border-orange-500/15">
                            <div class="attend-icon-box bg-orange-500/20">
                                <i class="fa-solid fa-plane-slash text-orange-400 text-sm"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-orange-400">Unpaid Leave</span>
                                <p class="text-lg font-extrabold text-white"><?php echo $payroll['unpaid_leave_days']; ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-purple-500/10 border border-purple-500/15">
                            <div class="attend-icon-box bg-purple-500/20">
                                <i class="fa-solid fa-stopwatch text-purple-400 text-sm"></i>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-purple-400">OT Hours</span>
                                <p class="text-lg font-extrabold text-white"><?php echo number_format($payroll['overtime_hours'] ?? 0, 1); ?>h</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($payroll && ($payroll['ot_amount'] ?? 0) > 0): ?>
                <?php
                    $month_start = sprintf('%04d-%02d-01', $payroll['payroll_year'], $payroll['payroll_month']);
                    $month_end = date('Y-m-t', strtotime($month_start));
                    $hr = $payroll['basic_salary'] > 0 ? round($payroll['basic_salary'] / (($payroll['working_days'] ?: 22) * 8), 2) : 0;
                    $ot_bd = get_overtime_payroll_breakdown($conn, $employee_id, $month_start, $month_end, $hr);
                ?>
                <div class="glass-card animate-fade-in-up stagger-3 mb-6">
                    <div class="p-5 border-b border-white/[0.06]">
                        <h3 class="font-bold text-white"><i class="fa-solid fa-stopwatch text-purple-400 mr-2"></i>Overtime Breakdown by Type</h3>
                    </div>
                    <div class="p-0">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-400 text-[10px] font-bold uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-3">Type</th>
                                    <th class="px-6 py-3 text-center">Requests</th>
                                    <th class="px-6 py-3 text-right">Hours</th>
                                    <th class="px-6 py-3 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04]">
                                <?php foreach (['working_day' => 'Working Day', 'weekend' => 'Weekend', 'holiday' => 'Holiday'] as $key => $label): ?>
                                <?php if (($ot_bd[$key]['hours'] ?? 0) > 0): ?>
                                <tr class="bg-white/[0.02]">
                                    <td class="px-6 py-3"><?php echo get_overtime_type_badge($key); ?></td>
                                    <td class="px-6 py-3 text-center text-zinc-400"><?php echo $ot_bd[$key]['count']; ?></td>
                                    <td class="px-6 py-3 text-right font-mono text-white font-semibold"><?php echo number_format($ot_bd[$key]['hours'], 1); ?>h</td>
                                    <td class="px-6 py-3 text-right font-mono text-emerald-400 font-semibold">$<?php echo number_format($ot_bd[$key]['amount'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                <tr class="bg-purple-500/10 border-t border-purple-500/20">
                                    <td class="px-6 py-3 text-purple-400 font-bold text-xs uppercase tracking-wider" colspan="2">OT Total</td>
                                    <td class="px-6 py-3 text-right font-mono text-white font-extrabold"><?php echo number_format($ot_bd['total_hours'], 1); ?>h</td>
                                    <td class="px-6 py-3 text-right font-mono text-emerald-400 font-extrabold">$<?php echo number_format($ot_bd['total_amount'], 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Financial Breakdown -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Earnings -->
                <div class="glass-card animate-fade-in-up stagger-4">
                    <div class="p-6 border-b border-white/[0.06]">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-green-500/10 flex items-center justify-center">
                                <i class="fa-solid fa-arrow-trend-up text-emerald-500"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-white text-lg">Earnings</h2>
                                <p class="text-xs text-zinc-500 mt-0.5">Income components</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-0">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-400 text-[10px] font-bold uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-3">Component</th>
                                    <th class="px-6 py-3 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04]">
                                <?php foreach ($earnings as $idx => $e): ?>
                                <tr class="<?php echo $idx % 2 === 0 ? 'bg-white/[0.02]' : ''; ?>">
                                    <td class="px-6 py-3 text-zinc-300 font-medium"><?php echo htmlspecialchars($e['component_name']); ?></td>
                                    <td class="px-6 py-3 text-right font-mono text-emerald-400 font-semibold">$<?php echo number_format($e['amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($earnings)): ?>
                                <tr>
                                    <td colspan="2" class="px-6 py-6 text-center text-zinc-500 text-sm">No earnings recorded</td>
                                </tr>
                                <?php endif; ?>
                                <tr class="bg-emerald-500/10 border-t border-emerald-500/20">
                                    <td class="px-6 py-3 text-emerald-400 font-bold text-xs uppercase tracking-wider">Gross Total</td>
                                    <td class="px-6 py-3 text-right font-mono text-emerald-400 font-extrabold">$<?php echo number_format($payroll['gross_salary'], 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Deductions -->
                <div class="glass-card animate-fade-in-up stagger-5">
                    <div class="p-6 border-b border-white/[0.06]">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-500/20 to-pink-500/10 flex items-center justify-center">
                                <i class="fa-solid fa-arrow-trend-down text-rose-500"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-white text-lg">Deductions</h2>
                                <p class="text-xs text-zinc-500 mt-0.5">Subtraction components</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-0">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-400 text-[10px] font-bold uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-3">Component</th>
                                    <th class="px-6 py-3 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04]">
                                <?php foreach ($deductions as $idx => $d): ?>
                                <tr class="<?php echo $idx % 2 === 0 ? 'bg-white/[0.02]' : ''; ?>">
                                    <td class="px-6 py-3 text-zinc-300 font-medium"><?php echo htmlspecialchars($d['component_name']); ?></td>
                                    <td class="px-6 py-3 text-right font-mono text-rose-400 font-semibold">$<?php echo number_format($d['amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($deductions)): ?>
                                <tr>
                                    <td colspan="2" class="px-6 py-6 text-center text-zinc-500 text-sm">No deductions recorded</td>
                                </tr>
                                <?php endif; ?>
                                <tr class="bg-rose-500/10 border-t border-rose-500/20">
                                    <td class="px-6 py-3 text-rose-400 font-bold text-xs uppercase tracking-wider">Total Deductions</td>
                                    <td class="px-6 py-3 text-right font-mono text-rose-400 font-extrabold">-$<?php echo number_format($payroll['deduction_amount'] + $payroll['tax_amount'], 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Net Salary -->
            <div class="net-gradient mb-6 p-8 animate-fade-in-up stagger-6">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-6">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-500/20 flex items-center justify-center">
                            <i class="fa-solid fa-sack-dollar text-emerald-400 text-2xl"></i>
                        </div>
                        <div>
                            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-400">Net Salary</span>
                            <p class="text-3xl font-extrabold text-white font-mono mt-0.5">$<?php echo number_format($payroll['net_salary'], 2); ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-zinc-400 font-mono">
                            <span class="text-emerald-400">$<?php echo number_format($payroll['gross_salary'], 2); ?></span>
                            <span class="text-zinc-500 mx-1">−</span>
                            <span class="text-rose-400">$<?php echo number_format($payroll['deduction_amount'] + $payroll['tax_amount'], 2); ?></span>
                            <span class="text-zinc-500 mx-1">=</span>
                            <span class="text-white font-bold">$<?php echo number_format($payroll['net_salary'], 2); ?></span>
                        </p>
                        <p class="text-[10px] text-zinc-500 mt-1">Gross − Deductions = Net</p>
                    </div>
                </div>
            </div>

            <!-- Download Slip Link -->
            <div class="flex justify-end mb-6 animate-fade-in-up stagger-7">
                <a href="download_slip.php?pid=<?php echo $payroll_id; ?>" class="action-btn bg-gradient-to-r from-amber-500 to-orange-500 text-white shadow-lg shadow-amber-500/25">
                    <i class="fa-solid fa-file-pdf"></i> Download Salary Slip (PDF)
                </a>
            </div>

            <?php endif; ?>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-6 lg:px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> HNIN AKARI NWE</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>
