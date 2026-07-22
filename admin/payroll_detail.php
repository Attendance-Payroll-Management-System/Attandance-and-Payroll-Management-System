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
        $action = $_POST['action'];
        $remarks = trim($_POST['remarks'] ?? '');

        $status_map = [
            'pay'    => 'Paid',
            'cancel' => 'Cancelled',
        ];

        if (isset($status_map[$action])) {
            $new_status = $status_map[$action];
            if (update_new_payroll_status($conn, $payroll_id, $new_status, $remarks ?: null)) {
                $message = "Payroll marked as $new_status successfully.";
                $message_type = 'success';
            } else {
                $message = 'Failed to update payroll status.';
                $message_type = 'error';
            }
        }
    }
}

$payroll = get_new_payroll_detail($conn, $payroll_id);

if (!$payroll) {
    $message = 'Payroll record not found.';
    $message_type = 'error';
}

$month_name = $payroll ? date('F', mktime(0, 0, 0, $payroll['pay_month'], 1)) : '';
$currency = get_system_setting($conn, 'payroll_currency', '$');

// Get attendance details for this employee/month
$attendance_details = [];
if ($payroll) {
    $month_start = sprintf('%04d-%02d-01', $payroll['pay_year'], $payroll['pay_month']);
    $month_end = date('Y-m-t', strtotime($month_start));

    $att_stmt = $conn->prepare("
        SELECT attendance_date, check_in, check_out, status, total_working_hours, is_late
        FROM attendance
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
        ORDER BY attendance_date ASC
    ");
    $att_stmt->bind_param('iss', $payroll['employee_id'], $month_start, $month_end);
    $att_stmt->execute();
    $attendance_details = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $att_stmt->close();
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
        .glass-card:hover::before { opacity: 1; }
        .detail-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #71717a;
        }
        .detail-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: white;
            margin-top: 0.25rem;
        }
        .earnings-item, .deductions-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.875rem 1rem;
            border-radius: 0.75rem;
            transition: all 0.2s ease;
        }
        .earnings-item {
            background: rgba(16,185,129,0.05);
            border: 1px solid rgba(16,185,129,0.1);
        }
        .earnings-item:hover {
            background: rgba(16,185,129,0.1);
        }
        .deductions-item {
            background: rgba(244,63,94,0.05);
            border: 1px solid rgba(244,63,94,0.1);
        }
        .deductions-item:hover {
            background: rgba(244,63,94,0.1);
        }
        .net-total {
            background: linear-gradient(135deg, rgba(139,92,246,0.15), rgba(217,70,239,0.1));
            border: 2px solid rgba(139,92,246,0.3);
            border-radius: 1rem;
            padding: 1.5rem;
            text-align: center;
        }
        .status-badge-lg {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .att-table {
            width: 100%;
            font-size: 0.75rem;
        }
        .att-table th {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #71717a;
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .att-table td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }
        .att-status {
            display: inline-flex;
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: capitalize;
        }
    </style>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Payroll Detail";
            $page_subtitle = $payroll ? $payroll['name'] . ' - ' . $month_name . ' ' . $payroll['pay_year'] : 'View payroll breakdown';
            ob_start();
        ?>
        <div class="flex items-center gap-3">
            <a href="payroll.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/[0.06] border border-white/10 text-zinc-400 hover:text-white hover:bg-white/[0.1] text-sm font-medium transition-all duration-200">
                <i class="fa-solid fa-arrow-left text-xs"></i> Back
            </a>
        </div>
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

            <?php if ($payroll): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Left Column: Employee Info + Attendance -->
                <div class="lg:col-span-1 space-y-6">
                    <!-- Employee Card -->
                    <div class="glass-card p-6">
                        <div class="flex items-center gap-4 mb-5">
                            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xl font-bold shadow-lg shadow-indigo-500/25">
                                <?php echo strtoupper(substr($payroll['name'], 0, 2)); ?>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-white"><?php echo htmlspecialchars($payroll['name']); ?></h3>
                                <p class="text-xs text-zinc-500 font-medium"><?php echo htmlspecialchars($payroll['employee_code']); ?></p>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="detail-label">Department</span>
                                <span class="detail-value text-sm"><?php echo htmlspecialchars($payroll['department_name'] ?? '-'); ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="detail-label">Position</span>
                                <span class="detail-value text-sm"><?php echo htmlspecialchars($payroll['position_name'] ?? '-'); ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="detail-label">Email</span>
                                <span class="detail-value text-sm"><?php echo htmlspecialchars($payroll['email'] ?? '-'); ?></span>
                            </div>
                            <div class="flex items-center justify-between pt-3 border-t border-white/[0.06]">
                                <span class="detail-label">Payment Status</span>
                                <span class="status-badge-lg <?php echo get_new_payroll_status_badge($payroll['payment_status']); ?>">
                                    <i class="fa-solid <?php echo get_new_payroll_status_icon($payroll['payment_status']); ?>"></i>
                                    <?php echo $payroll['payment_status']; ?>
                                </span>
                            </div>
                            <?php if ($payroll['paid_date']): ?>
                            <div class="flex items-center justify-between">
                                <span class="detail-label">Paid Date</span>
                                <span class="detail-value text-sm text-emerald-400"><?php echo date('M d, Y', strtotime($payroll['paid_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Attendance Summary -->
                    <div class="glass-card p-6">
                        <h4 class="text-sm font-bold text-white mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-calendar-check text-emerald-400"></i>
                            Attendance Summary
                        </h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="text-center p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/15">
                                <p class="text-2xl font-extrabold text-emerald-400"><?php echo $payroll['present_days']; ?></p>
                                <p class="text-[10px] text-zinc-500 font-bold uppercase mt-1">Present</p>
                            </div>
                            <div class="text-center p-3 rounded-xl bg-teal-500/10 border border-teal-500/15">
                                <p class="text-2xl font-extrabold text-teal-400"><?php echo $payroll['half_days'] ?? 0; ?></p>
                                <p class="text-[10px] text-zinc-500 font-bold uppercase mt-1">Half Days</p>
                            </div>
                            <div class="text-center p-3 rounded-xl bg-blue-500/10 border border-blue-500/15">
                                <p class="text-2xl font-extrabold text-blue-400"><?php echo $payroll['leave_days']; ?></p>
                                <p class="text-[10px] text-zinc-500 font-bold uppercase mt-1">Leave</p>
                            </div>
                            <div class="text-center p-3 rounded-xl bg-amber-500/10 border border-amber-500/15">
                                <p class="text-2xl font-extrabold text-amber-400"><?php echo $payroll['late_days']; ?></p>
                                <p class="text-[10px] text-zinc-500 font-bold uppercase mt-1">Late</p>
                            </div>
                            <div class="text-center p-3 rounded-xl bg-red-500/10 border border-red-500/15">
                                <p class="text-2xl font-extrabold text-red-400"><?php echo $payroll['absent_days']; ?></p>
                                <p class="text-[10px] text-zinc-500 font-bold uppercase mt-1">Absent</p>
                            </div>
                            <div class="text-center p-3 rounded-xl bg-purple-500/10 border border-purple-500/15">
                                <p class="text-2xl font-extrabold text-purple-400"><?php echo number_format($payroll['overtime_hours'], 1); ?>h</p>
                                <p class="text-[10px] text-zinc-500 font-bold uppercase mt-1">OT Hours</p>
                            </div>
                        </div>
                        <div class="mt-3 text-center">
                            <span class="text-xs text-zinc-500">Working Days: <strong class="text-white"><?php echo $payroll['working_days']; ?></strong></span>
                        </div>
                    </div>

                    <!-- Status Actions -->
                    <?php if ($payroll['payment_status'] !== 'Paid'): ?>
                    <div class="glass-card p-6">
                        <h4 class="text-sm font-bold text-white mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-gear text-zinc-400"></i>
                            Actions
                        </h4>
                        <div class="space-y-3">
                            <?php if ($payroll['payment_status'] === 'Pending'): ?>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="pay">
                                <div class="mb-3">
                                    <label class="text-xs font-semibold text-zinc-400 mb-1 block">Remarks (optional)</label>
                                    <input type="text" name="remarks" class="w-full bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-emerald-500/30" placeholder="Payment notes...">
                                </div>
                                <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-semibold text-sm hover:shadow-lg hover:shadow-emerald-500/25 transition-all duration-200">
                                    <i class="fa-solid fa-circle-check"></i> Mark as Paid
                                </button>
                            </form>
                            <?php endif; ?>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="cancel">
                                <div class="mb-3">
                                    <label class="text-xs font-semibold text-zinc-400 mb-1 block">Cancel Reason</label>
                                    <input type="text" name="remarks" class="w-full bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-rose-500/30" placeholder="Reason for cancellation..." required>
                                </div>
                                <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-rose-500 to-pink-500 text-white font-semibold text-sm hover:shadow-lg hover:shadow-rose-500/25 transition-all duration-200">
                                    <i class="fa-solid fa-ban"></i> Cancel Payroll
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column: Salary Breakdown -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Salary Breakdown -->
                    <div class="glass-card p-6">
                        <h4 class="text-sm font-bold text-white mb-5 flex items-center gap-2">
                            <i class="fa-solid fa-calculator text-blue-400"></i>
                            Salary Breakdown — <?php echo $month_name . ' ' . $payroll['pay_year']; ?>
                        </h4>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Earnings -->
                            <div>
                                <h5 class="text-xs font-bold text-emerald-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                                    <i class="fa-solid fa-arrow-trend-up text-[10px]"></i> Earnings
                                </h5>
                                <div class="space-y-2">
                                    <div class="earnings-item">
                                        <span class="text-sm text-zinc-300">Basic Salary</span>
                                        <span class="font-mono font-bold text-emerald-400"><?php echo $currency; ?><?php echo number_format($payroll['basic_salary'], 2); ?></span>
                                    </div>
                                    <?php if (($payroll['attendance_salary'] ?? 0) > 0 && $payroll['attendance_salary'] != $payroll['basic_salary']): ?>
                                    <div class="earnings-item">
                                        <span class="text-sm text-zinc-300">Attendance Salary</span>
                                        <span class="font-mono font-bold text-emerald-400"><?php echo $currency; ?><?php echo number_format($payroll['attendance_salary'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="earnings-item">
                                        <span class="text-sm text-zinc-300">Allowance</span>
                                        <span class="font-mono font-bold text-emerald-400"><?php echo $currency; ?><?php echo number_format($payroll['allowance'], 2); ?></span>
                                    </div>
                                    <div class="earnings-item">
                                        <span class="text-sm text-zinc-300">Overtime Pay</span>
                                        <span class="font-mono font-bold text-emerald-400"><?php echo $currency; ?><?php echo number_format($payroll['overtime_amount'], 2); ?></span>
                                    </div>
                                    <div class="earnings-item">
                                        <span class="text-sm text-zinc-300">Bonus</span>
                                        <span class="font-mono font-bold text-emerald-400"><?php echo $currency; ?><?php echo number_format($payroll['bonus'], 2); ?></span>
                                    </div>
                                    <div class="earnings-item bg-emerald-500/10 border border-emerald-500/20">
                                        <span class="text-sm font-bold text-emerald-400">Gross Salary</span>
                                        <span class="font-mono font-bold text-emerald-400 text-lg"><?php echo $currency; ?><?php echo number_format($payroll['gross_salary'], 2); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Deductions -->
                            <div>
                                <h5 class="text-xs font-bold text-rose-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                                    <i class="fa-solid fa-arrow-trend-down text-[10px]"></i> Deductions
                                </h5>
                                <div class="space-y-2">
                                    <?php if ($payroll['leave_deduction'] > 0): ?>
                                    <div class="deductions-item">
                                        <span class="text-sm text-zinc-300">Leave Deduction</span>
                                        <span class="font-mono font-bold text-rose-400"><?php echo $currency; ?><?php echo number_format($payroll['leave_deduction'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (($payroll['unpaid_leave_deduction'] ?? 0) > 0): ?>
                                    <div class="deductions-item">
                                        <span class="text-sm text-zinc-300">Unpaid Leave Deduction</span>
                                        <span class="font-mono font-bold text-rose-400"><?php echo $currency; ?><?php echo number_format($payroll['unpaid_leave_deduction'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (($payroll['half_day_deduction'] ?? 0) > 0): ?>
                                    <div class="deductions-item">
                                        <span class="text-sm text-zinc-300">Half-Day Deduction</span>
                                        <span class="font-mono font-bold text-rose-400"><?php echo $currency; ?><?php echo number_format($payroll['half_day_deduction'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($payroll['late_deduction'] > 0): ?>
                                    <div class="deductions-item">
                                        <span class="text-sm text-zinc-300">Late Deduction</span>
                                        <span class="font-mono font-bold text-rose-400"><?php echo $currency; ?><?php echo number_format($payroll['late_deduction'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($payroll['absent_deduction'] > 0): ?>
                                    <div class="deductions-item">
                                        <span class="text-sm text-zinc-300">Absent Deduction (AWOL)</span>
                                        <span class="font-mono font-bold text-rose-400"><?php echo $currency; ?><?php echo number_format($payroll['absent_deduction'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($payroll['other_deduction'] > 0): ?>
                                    <div class="deductions-item">
                                        <span class="text-sm text-zinc-300">Other Deductions</span>
                                        <span class="font-mono font-bold text-rose-400"><?php echo $currency; ?><?php echo number_format($payroll['other_deduction'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php
                                    $total_ded = ($payroll['leave_deduction'] ?? 0) + ($payroll['unpaid_leave_deduction'] ?? 0) + ($payroll['half_day_deduction'] ?? 0) + ($payroll['late_deduction'] ?? 0) + ($payroll['absent_deduction'] ?? 0) + ($payroll['other_deduction'] ?? 0);
                                    ?>
                                    <div class="deductions-item bg-rose-500/10 border border-rose-500/20">
                                        <span class="text-sm font-bold text-rose-400">Total Deductions</span>
                                        <span class="font-mono font-bold text-rose-400 text-lg"><?php echo $currency; ?><?php echo number_format($total_ded, 2); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Net Total -->
                        <div class="mt-6 net-total">
                            <p class="text-xs font-bold text-zinc-500 uppercase tracking-wider mb-1">Net Salary</p>
                            <p class="text-3xl font-extrabold text-purple-400"><?php echo $currency; ?><?php echo number_format($payroll['net_salary'], 2); ?></p>
                            <p class="text-xs text-zinc-500 mt-2">Gross <?php echo $currency; ?><?php echo number_format($payroll['gross_salary'], 2); ?> - Deductions <?php echo $currency; ?><?php echo number_format($total_ded, 2); ?></p>
                        </div>
                    </div>

                    <!-- Attendance Details -->
                    <?php if (!empty($attendance_details)): ?>
                    <div class="glass-card p-6">
                        <h4 class="text-sm font-bold text-white mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-list-check text-cyan-400"></i>
                            Attendance Details
                        </h4>
                        <div class="overflow-x-auto max-h-80 overflow-y-auto">
                            <table class="att-table">
                                <thead class="sticky top-0 bg-[#0F172A]">
                                    <tr>
                                        <th class="text-left">Date</th>
                                        <th class="text-center">Check In</th>
                                        <th class="text-center">Check Out</th>
                                        <th class="text-center">Hours</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Late</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendance_details as $att): ?>
                                    <?php
                                        $status_colors = [
                                            'present' => 'bg-emerald-500/20 text-emerald-400',
                                            'late' => 'bg-amber-500/20 text-amber-400',
                                            'absent' => 'bg-red-500/20 text-red-400',
                                            'awol' => 'bg-red-500/20 text-red-400',
                                            'paid_leave' => 'bg-blue-500/20 text-blue-400',
                                            'unpaid_leave' => 'bg-blue-500/20 text-blue-400',
                                            'leave' => 'bg-blue-500/20 text-blue-400',
                                            'weekend' => 'bg-zinc-500/20 text-zinc-400',
                                            'public_holiday' => 'bg-purple-500/20 text-purple-400',
                                            'half_day' => 'bg-orange-500/20 text-orange-400',
                                        ];
                                        $s_color = $status_colors[$att['status']] ?? 'bg-zinc-500/20 text-zinc-400';
                                    ?>
                                    <tr class="hover:bg-white/[0.02]">
                                        <td class="text-zinc-300 font-medium"><?php echo date('M d (D)', strtotime($att['attendance_date'])); ?></td>
                                        <td class="text-center text-zinc-400 font-mono text-xs"><?php echo $att['check_in'] ? date('h:i A', strtotime($att['check_in'])) : '-'; ?></td>
                                        <td class="text-center text-zinc-400 font-mono text-xs"><?php echo $att['check_out'] ? date('h:i A', strtotime($att['check_out'])) : '-'; ?></td>
                                        <td class="text-center text-zinc-400 font-mono text-xs"><?php echo $att['total_working_hours'] ? number_format($att['total_working_hours'], 1) . 'h' : '-'; ?></td>
                                        <td class="text-center">
                                            <span class="att-status <?php echo $s_color; ?>"><?php echo str_replace('_', ' ', $att['status']); ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($att['is_late']): ?>
                                                <i class="fa-solid fa-clock text-amber-400 text-xs"></i>
                                            <?php else: ?>
                                                <span class="text-zinc-600">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Remarks -->
                    <?php if ($payroll['remarks']): ?>
                    <div class="glass-card p-6">
                        <h4 class="text-sm font-bold text-white mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-comment text-zinc-400"></i>
                            Remarks
                        </h4>
                        <p class="text-sm text-zinc-300 leading-relaxed"><?php echo nl2br(htmlspecialchars($payroll['remarks'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="glass-card p-16 text-center">
                <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-red-500/10 to-pink-500/10 flex items-center justify-center mx-auto mb-5">
                    <i class="fa-solid fa-circle-exclamation text-3xl text-zinc-500"></i>
                </div>
                <p class="text-zinc-400 font-medium text-lg">Payroll record not found.</p>
                <a href="payroll.php" class="inline-flex items-center gap-2 mt-4 px-5 py-2.5 rounded-xl bg-indigo-500/15 text-indigo-400 hover:bg-indigo-500/25 text-sm font-semibold transition-colors">
                    <i class="fa-solid fa-arrow-left"></i> Back to Payroll
                </a>
            </div>
            <?php endif; ?>
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
