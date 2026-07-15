<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$payroll_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';
$message_type = '';

if (!$payroll_id) {
    header('Location: payroll.php');
    exit;
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validate_csrf_token()) {
        $message = 'Invalid CSRF token.';
        $message_type = 'error';
    } else {
        $admin_id = $_SESSION['admin_id'] ?? null;
        $action = $_POST['action'];
        $remarks = trim($_POST['remarks'] ?? '');

        $status_map = [
            'review' => 'Reviewed',
            'approve' => 'Approved',
            'pay' => 'Paid',
            'cancel' => 'Cancelled',
        ];

        if (isset($status_map[$action])) {
            $new_status = $status_map[$action];
            if (update_payroll_status($conn, $payroll_id, $new_status, $admin_id, $remarks)) {
                $message = "Payroll status updated to $new_status successfully.";
                $message_type = 'success';
            } else {
                $message = 'Failed to update payroll status.';
                $message_type = 'error';
            }
        }
    }
}

$payroll = get_payroll_details_with_components($conn, $payroll_id);

if (!$payroll) {
    $message = 'Payroll record not found.';
    $message_type = 'error';
}

$earnings = [];
$deductions = [];
if ($payroll) {
    foreach ($payroll['details'] as $detail) {
        if ($detail['component_type'] === 'earning') {
            $earnings[] = $detail;
        } else {
            $deductions[] = $detail;
        }
    }
}

$month_name = $payroll ? date('F', mktime(0, 0, 0, $payroll['payroll_month'], 1)) : '';
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
        .timeline-item {
            position: relative;
            padding-left: 2rem;
            padding-bottom: 1.5rem;
        }
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 1.5rem;
            bottom: 0;
            width: 2px;
            background: rgba(255,255,255,0.08);
        }
        .timeline-item:last-child::before {
            display: none;
        }
        .timeline-dot {
            position: absolute;
            left: 0.15rem;
            top: 0.25rem;
            width: 0.9rem;
            height: 0.9rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .timeline-dot::after {
            content: '';
            width: 0.45rem;
            height: 0.45rem;
            border-radius: 50%;
            background: currentColor;
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
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Payroll Detail";
            $page_subtitle = $payroll ? "Detailed view for " . htmlspecialchars($payroll['name']) . " — " . $month_name . " " . $payroll['payroll_year'] : "Payroll detail view";
            ob_start();
        ?>
        <a href="payroll.php" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i> Back to Payroll
        </a>
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

            <?php if (!$payroll): ?>
                <div class="glass-card p-12 text-center animate-fade-in-up">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-rose-500/15 to-pink-500/10 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-circle-exclamation text-3xl text-rose-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">Payroll Not Found</h3>
                    <p class="text-zinc-400 text-sm mb-6">The payroll record you are looking for does not exist or has been removed.</p>
                    <a href="payroll.php" class="action-btn bg-gradient-to-r from-blue-500 to-indigo-500 text-white shadow-lg shadow-blue-500/25">
                        <i class="fa-solid fa-arrow-left"></i> Back to Payroll
                    </a>
                </div>
            <?php else: ?>

                <!-- Section A: Header with Status Badge -->
                <div class="flex flex-wrap items-center justify-between gap-4 mb-8 animate-fade-in-up">
                    <div>
                        <h1 class="text-2xl font-extrabold text-white">Payroll Detail</h1>
                        <p class="text-sm text-zinc-400 mt-1">
                            <?php echo htmlspecialchars($payroll['name']); ?> · <?php echo htmlspecialchars($payroll['employee_code']); ?> · <?php echo $month_name . ' ' . $payroll['payroll_year']; ?>
                        </p>
                    </div>
                    <span class="status-badge <?php echo get_payroll_status_badge($payroll['status']); ?>">
                        <i class="fa-solid <?php echo get_payroll_status_icon($payroll['status']); ?> text-[10px]"></i>
                        <?php echo htmlspecialchars($payroll['status']); ?>
                    </span>
                </div>

                <!-- Section B: Employee Info Card -->
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
                                <span class="info-value"><?php echo htmlspecialchars($payroll['name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Employee Code</span>
                                <span class="info-value"><?php echo htmlspecialchars($payroll['employee_code']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Department</span>
                                <span class="info-value"><?php echo htmlspecialchars($payroll['department_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Position</span>
                                <span class="info-value"><?php echo htmlspecialchars($payroll['position_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Email</span>
                                <span class="info-value"><?php echo htmlspecialchars($payroll['email'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section C: Payroll Info Card -->
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

                <!-- Section D: Attendance Summary Card -->
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
                                    <p class="text-lg font-extrabold text-white"><?php echo number_format($payroll['overtime_hours'], 1); ?>h</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($payroll && $payroll['ot_amount'] > 0): ?>
                <?php
                    $month_start = sprintf('%04d-%02d-01', $payroll['payroll_year'], $payroll['payroll_month']);
                    $month_end = date('Y-m-t', strtotime($month_start));
                    $ot_breakdown = get_overtime_payroll_breakdown($conn, $payroll['employee_id'], $month_start, $month_end, $payroll['hourly_rate'] ?? 0);
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
                                <?php if (($ot_breakdown[$key]['hours'] ?? 0) > 0): ?>
                                <tr class="bg-white/[0.02]">
                                    <td class="px-6 py-3"><?php echo get_overtime_type_badge($key); ?></td>
                                    <td class="px-6 py-3 text-center text-zinc-400"><?php echo $ot_breakdown[$key]['count']; ?></td>
                                    <td class="px-6 py-3 text-right font-mono text-white font-semibold"><?php echo number_format($ot_breakdown[$key]['hours'], 1); ?>h</td>
                                    <td class="px-6 py-3 text-right font-mono text-emerald-400 font-semibold">$<?php echo number_format($ot_breakdown[$key]['amount'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                <tr class="bg-purple-500/10 border-t border-purple-500/20">
                                    <td class="px-6 py-3 text-purple-400 font-bold text-xs uppercase tracking-wider" colspan="2">OT Total</td>
                                    <td class="px-6 py-3 text-right font-mono text-white font-extrabold"><?php echo number_format($ot_breakdown['total_hours'], 1); ?>h</td>
                                    <td class="px-6 py-3 text-right font-mono text-emerald-400 font-extrabold">$<?php echo number_format($ot_breakdown['total_amount'], 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Section E: Financial Breakdown -->
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
                                        <td class="px-6 py-3 text-right font-mono text-rose-400 font-extrabold">$<?php echo number_format($payroll['deduction_amount'] + $payroll['tax_amount'], 2); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Section F: Net Salary Card -->
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

                <!-- Section G: Approval History Timeline -->
                <div class="glass-card mb-6 animate-fade-in-up stagger-7">
                    <div class="p-6 border-b border-white/[0.06]">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500/20 to-violet-500/10 flex items-center justify-center">
                                <i class="fa-solid fa-timeline text-indigo-500"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-white text-lg">Approval History</h2>
                                <p class="text-xs text-zinc-500 mt-0.5">Status transition timeline</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($payroll['approvals'])): ?>
                            <div class="space-y-0">
                                <?php foreach ($payroll['approvals'] as $idx => $approval): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot <?php echo $approval['to_status'] === 'Paid' ? 'text-emerald-400' : ($approval['to_status'] === 'Cancelled' ? 'text-rose-400' : 'text-blue-400'); ?>"></div>
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                                        <div>
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="status-badge <?php echo get_payroll_status_badge($approval['from_status']); ?> text-[10px] py-1 px-2">
                                                    <?php echo htmlspecialchars($approval['from_status']); ?>
                                                </span>
                                                <i class="fa-solid fa-arrow-right text-zinc-600 text-[10px]"></i>
                                                <span class="status-badge <?php echo get_payroll_status_badge($approval['to_status']); ?> text-[10px] py-1 px-2">
                                                    <?php echo htmlspecialchars($approval['to_status']); ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($approval['remarks'])): ?>
                                            <p class="text-xs text-zinc-400 mt-1.5 ml-1 italic">"<?php echo htmlspecialchars($approval['remarks']); ?>"</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <p class="text-xs text-zinc-400">
                                                <i class="fa-solid fa-user-tie text-zinc-500 mr-1"></i>
                                                <?php echo htmlspecialchars($approval['action_by_name'] ?? 'System'); ?>
                                            </p>
                                            <p class="text-[10px] text-zinc-500 mt-0.5"><?php echo htmlspecialchars(format_mmt($approval['created_at'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-12 h-12 rounded-xl bg-zinc-500/10 flex items-center justify-center mx-auto mb-3">
                                    <i class="fa-solid fa-clock-rotate-left text-zinc-500 text-lg"></i>
                                </div>
                                <p class="text-zinc-500 text-sm">No approval history yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Section H: Action Buttons -->
                <?php if (!in_array($payroll['status'], ['Paid', 'Cancelled'])): ?>
                <div class="glass-card mb-6 animate-fade-in-up stagger-8">
                    <div class="p-6 border-b border-white/[0.06]">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-500/10 flex items-center justify-center">
                                <i class="fa-solid fa-bolt text-cyan-500"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-white text-lg">Actions</h2>
                                <p class="text-xs text-zinc-500 mt-0.5">Update payroll status</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-6 flex flex-wrap gap-3">
                        <?php if ($payroll['status'] === 'Generated'): ?>
                        <form method="POST" class="inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="review">
                            <div class="flex items-center gap-2">
                                <input type="text" name="remarks" placeholder="Remarks (optional)" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-cyan-500/30 w-48">
                                <button type="submit" class="action-btn bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-lg shadow-cyan-500/25">
                                    <i class="fa-solid fa-magnifying-glass"></i> Mark as Reviewed
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($payroll['status'] === 'Reviewed'): ?>
                        <form method="POST" class="inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="approve">
                            <div class="flex items-center gap-2">
                                <input type="text" name="remarks" placeholder="Remarks (optional)" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-emerald-500/30 w-48">
                                <button type="submit" class="action-btn bg-gradient-to-r from-emerald-500 to-green-500 text-white shadow-lg shadow-emerald-500/25">
                                    <i class="fa-solid fa-check-circle"></i> Approve Payroll
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($payroll['status'] === 'Approved'): ?>
                        <form method="POST" class="inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="pay">
                            <div class="flex items-center gap-2">
                                <input type="text" name="remarks" placeholder="Remarks (optional)" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-purple-500/30 w-48">
                                <button type="submit" class="action-btn bg-gradient-to-r from-purple-500 to-violet-500 text-white shadow-lg shadow-purple-500/25">
                                    <i class="fa-solid fa-sack-dollar"></i> Mark as Paid
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <button type="button" @click="$dispatch('open-cancel-modal')" class="action-btn bg-gradient-to-r from-rose-500 to-pink-500 text-white shadow-lg shadow-rose-500/25 ml-auto">
                            <i class="fa-solid fa-ban"></i> Cancel
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Download PDF Button -->
                <div class="flex justify-end mb-6 animate-fade-in-up stagger-9">
                    <a href="salary_slip.php?download_pdf=1&pid=<?php echo $payroll_id; ?>" class="action-btn bg-white/[0.06] border border-white/10 text-zinc-300 hover:text-white hover:bg-white/[0.1]">
                        <i class="fa-solid fa-file-pdf text-rose-400"></i> Download Salary Slip (PDF)
                    </a>
                </div>

            <?php endif; ?>
        </main>

        <!-- Cancel Modal -->
        <div x-data="{ show: false }" @open-cancel-modal.window="show = true" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="show = false"></div>
            <div class="relative glass-card w-full max-w-md p-6 z-10" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" @click.away="show = false">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-rose-500/20 flex items-center justify-center">
                        <i class="fa-solid fa-triangle-exclamation text-rose-400"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white">Cancel Payroll</h3>
                        <p class="text-xs text-zinc-500">This action cannot be undone</p>
                    </div>
                </div>
                <p class="text-sm text-zinc-400 mb-4">Are you sure you want to cancel this payroll? The status will be changed to <span class="font-semibold text-rose-400">Cancelled</span>.</p>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cancel">
                    <div class="mb-4">
                        <label class="text-xs font-bold text-zinc-400 uppercase tracking-wider mb-1.5 block">Reason for Cancellation</label>
                        <input type="text" name="remarks" required placeholder="Enter reason..." class="w-full bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-rose-500/30">
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" @click="show = false" class="action-btn bg-white/[0.06] border border-white/10 text-zinc-300 hover:text-white hover:bg-white/[0.1]">
                            <i class="fa-solid fa-xmark"></i> Close
                        </button>
                        <button type="submit" class="action-btn bg-gradient-to-r from-rose-500 to-pink-500 text-white shadow-lg shadow-rose-500/25">
                            <i class="fa-solid fa-ban"></i> Confirm Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

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