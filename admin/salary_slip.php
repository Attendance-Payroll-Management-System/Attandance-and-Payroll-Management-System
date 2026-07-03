<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/pdf_generator.php';
require_once '../config/smtp_mailer.php';

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month_name = date('F', mktime(0, 0, 0, $selected_month, 1));
$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

$message = '';
$message_type = '';

// ─── Helper: Get email log for a payroll ──────────────
function get_email_status($conn, $payroll_id) {
    $stmt = $conn->prepare("SELECT status, sent_at FROM email_logs WHERE payroll_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $payroll_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

// ─── Helper: Build email HTML body ────────────────────
function build_email_body($emp_name, $month_name, $year) {
    return "
    <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;\">
        <div style=\"text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #1e293b;\">
            <h2 style=\"color: #1e293b; margin: 0;\">HNIN AKARI NWE</h2>
            <p style=\"color: #64748b; font-size: 12px; margin: 4px 0 0;\">Payroll Management System</p>
        </div>
        <p>Dear <strong>" . htmlspecialchars($emp_name) . "</strong>,</p>
        <p>Please find attached your salary slip for <strong>{$month_name} {$year}</strong>.</p>
        <p>If you have any questions regarding your salary details, please contact the HR Department.</p>
        <p>Best regards,<br><strong>HR Department</strong><br>Hnin AKari nwe</p>
        <div style=\"text-align: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;\">
            © " . date('Y') . " Hnin AKari nwe. All rights reserved.
        </div>
    </div>";
}

// ─── Helper: Send single salary slip email ────────────
function send_slip_email($conn, $payroll_id, $selected_month, $selected_year, $month_name) {
    $stmt = $conn->prepare("
        SELECT p.*, e.name, e.email, e.employee_code
        FROM payrolls p JOIN employee e ON p.employee_id = e.id WHERE p.id = ?
    ");
    $stmt->bind_param('i', $payroll_id);
    $stmt->execute();
    $slip = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$slip || empty($slip['email'])) {
        return ['success' => false, 'message' => 'Employee has no email address on file.'];
    }

    if (!filter_var($slip['email'], FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address: ' . htmlspecialchars($slip['email'])];
    }

    // Generate PDF
    $pdf = new SalaryPDF();
    $pdfContent = $pdf->generate($slip, $month_name, $selected_year);
    $filename = "Salary_Slip_{$slip['employee_code']}_{$month_name}_{$selected_year}.pdf";

    $subject = "Salary Slip for {$month_name} {$selected_year}";
    $body = build_email_body($slip['name'], $month_name, $selected_year);
    $smtpConfig = require __DIR__ . '/../config/smtp.php';

    // Insert log as pending
    $logStmt = $conn->prepare("INSERT INTO email_logs (payroll_id, employee_id, recipient_email, subject, status) VALUES (?, ?, ?, ?, 'pending')");
    $logStmt->bind_param('iiss', $payroll_id, $slip['employee_id'], $slip['email'], $subject);
    $logStmt->execute();
    $logId = $logStmt->insert_id;
    $logStmt->close();

    try {
        smtp_mail($slip['email'], $subject, $body, $smtpConfig, [$filename => $pdfContent]);

        // Update log to sent
        $upStmt = $conn->prepare("UPDATE email_logs SET status = 'sent', sent_at = NOW() WHERE id = ?");
        $upStmt->bind_param('i', $logId);
        $upStmt->execute();
        $upStmt->close();

        return ['success' => true, 'message' => 'Salary slip sent to ' . htmlspecialchars($slip['email'])];
    } catch (Exception $e) {
        // Update log to failed
        $errMsg = $e->getMessage();
        $upStmt = $conn->prepare("UPDATE email_logs SET status = 'failed', error_message = ? WHERE id = ?");
        $upStmt->bind_param('si', $errMsg, $logId);
        $upStmt->execute();
        $upStmt->close();

        return ['success' => false, 'message' => 'Failed to send email: ' . htmlspecialchars($e->getMessage())];
    }
}

// ─── Handle Actions ───────────────────────────────────

// Single send / resend
if (isset($_POST['send_email']) && isset($_POST['payroll_id'])) {
    $result = send_slip_email($conn, (int)$_POST['payroll_id'], $selected_month, $selected_year, $month_name);
    $message = $result['message'];
    $message_type = $result['success'] ? 'success' : 'error';
}

// Bulk send all
if (isset($_POST['send_all'])) {
    $stmt = $conn->prepare("
        SELECT p.id FROM payrolls p
        JOIN employee e ON p.employee_id = e.id
        WHERE p.payroll_month = ? AND p.payroll_year = ? AND e.email IS NOT NULL AND e.email != ''
    ");
    $stmt->bind_param('ii', $selected_month, $selected_year);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $sent = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $result = send_slip_email($conn, $row['id'], $selected_month, $selected_year, $month_name);
        if ($result['success']) $sent++;
        else $failed++;
    }
    $total = count($rows);
    $message = "Bulk send complete: {$sent}/{$total} sent successfully" . ($failed > 0 ? ", {$failed} failed" : "");
    $message_type = $sent > 0 ? 'success' : 'error';
}

// PDF download
if (isset($_GET['download_pdf']) && isset($_GET['pid'])) {
    $pid = (int)$_GET['pid'];
    $stmt = $conn->prepare("
        SELECT p.*, e.name, e.email, e.employee_code
        FROM payrolls p JOIN employee e ON p.employee_id = e.id WHERE p.id = ?
    ");
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $slip = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($slip) {
        $pdf = new SalaryPDF();
        $pdfContent = $pdf->generate($slip, $month_name, $selected_year);
        $filename = "Salary_Slip_{$slip['employee_code']}_{$month_name}_{$selected_year}.pdf";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: no-cache, must-revalidate');
        echo $pdfContent;
        exit;
    }
}

// ─── Fetch payroll data ───────────────────────────────
$payrolls = $conn->prepare("
    SELECT p.*, e.name, e.email, e.employee_code, e.basic_salary as emp_salary
    FROM payrolls p JOIN employee e ON p.employee_id = e.id
    WHERE p.payroll_month = ? AND p.payroll_year = ?
    ORDER BY e.name ASC
");
$payrolls->bind_param('ii', $selected_month, $selected_year);
$payrolls->execute();
$payroll_data = $payrolls->get_result()->fetch_all(MYSQLI_ASSOC);
$payrolls->close();

$total_net = array_sum(array_column($payroll_data, 'net_salary'));
$emp_count = count($payroll_data);

// Email stats
$statsStmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM email_logs el JOIN payrolls p ON el.payroll_id = p.id WHERE p.payroll_month = ? AND p.payroll_year = ? GROUP BY status");
$statsStmt->bind_param('ii', $selected_month, $selected_year);
$statsStmt->execute();
$statsResult = $statsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$statsStmt->close();
$emailStats = ['sent' => 0, 'failed' => 0, 'pending' => 0];
foreach ($statsResult as $s) {
    $emailStats[$s['status']] = (int)$s['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Salary Slip</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Salary Slips"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <div class="max-w-7xl mx-auto">
                <!-- Header -->
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8 animate-fade-in-up">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500/20 to-fuchsia-500/20 flex items-center justify-center">
                            <i class="fas fa-file-invoice text-violet-400"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-body">Salary Slips</h1>
                            <p class="text-sm text-body-secondary">Generate, download, and email salary slips to employees</p>
                        </div>
                    </div>
                    <form method="GET" class="flex items-center gap-3 glass-strong rounded-xl p-3">
                        <select name="month" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-violet-500/30">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-violet-500/30">
                            <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                            <i class="fa-solid fa-magnifying-glass"></i> View
                        </button>
                    </form>
                </div>

                <!-- Notification -->
                <?php if ($message): ?>
                    <div class="mb-6 rounded-2xl px-6 py-4 border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?> animate-fade-in-up">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                            <p class="font-medium"><?php echo $message; ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($emp_count > 0): ?>
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8 animate-fade-in-up stagger-1">
                    <div class="glass-strong rounded-xl p-4">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Period</span>
                        <div class="text-lg font-bold text-white mt-1"><?php echo $month_name . ' ' . $selected_year; ?></div>
                    </div>
                    <div class="glass-strong rounded-xl p-4">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Employees</span>
                        <div class="text-lg font-bold text-white mt-1"><?php echo $emp_count; ?></div>
                    </div>
                    <div class="glass-strong rounded-xl p-4">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Total Net Pay</span>
                        <div class="text-lg font-bold text-emerald-400 mt-1">$<?php echo number_format($total_net, 2); ?></div>
                    </div>
                    <div class="glass-strong rounded-xl p-4">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Emails Sent</span>
                        <div class="text-lg font-bold mt-1">
                            <span class="text-emerald-400"><?php echo $emailStats['sent']; ?> sent</span>
                            <?php if ($emailStats['failed'] > 0): ?>
                            <span class="text-rose-400 text-sm ml-2"><?php echo $emailStats['failed']; ?> failed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bulk Send Button -->
                <div class="flex justify-end mb-6 animate-fade-in-up stagger-2">
                    <form method="POST" onsubmit="return confirm('Send salary slips to ALL employees for <?php echo $month_name . ' ' . $selected_year; ?>?')">
                        <button type="submit" name="send_all" value="1" class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-semibold text-sm px-6 py-2.5 shadow-sm transition flex items-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i> Send All Slips
                        </button>
                    </form>
                </div>

                <!-- Salary Slips Table -->
                <div class="glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in-up stagger-2">
                    <div class="p-6 border-b border-white/[0.06]">
                        <h2 class="text-lg font-bold text-white">Employee Salary Slips</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="px-6 py-4">Employee</th>
                                    <th class="px-6 py-4 text-right">Basic</th>
                                    <th class="px-6 py-4 text-right">OT</th>
                                    <th class="px-6 py-4 text-right">Bonus</th>
                                    <th class="px-6 py-4 text-right">Deduction</th>
                                    <th class="px-6 py-4 text-right">Net</th>
                                    <th class="px-6 py-4 text-center">Email</th>
                                    <th class="px-6 py-4 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06]">
                                <?php foreach ($payroll_data as $p):
                                    $emailStatus = get_email_status($conn, $p['id']);
                                ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <a href="?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>&download_pdf=1&pid=<?php echo $p['id']; ?>" title="Download PDF" class="w-9 h-9 rounded-lg bg-amber-500/20 text-amber-400 hover:bg-amber-500/40 flex items-center justify-center transition shrink-0">
                                                <i class="fa-solid fa-file-pdf"></i>
                                            </a>
                                            <div>
                                                <div class="font-medium text-white"><?php echo htmlspecialchars($p['name']); ?></div>
                                                <div class="text-[11px] text-zinc-500"><?php echo htmlspecialchars($p['employee_code']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-right font-mono text-white">$<?php echo number_format($p['basic_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right font-mono text-amber-400">$<?php echo number_format($p['ot_amount'] ?: 0, 2); ?></td>
                                    <td class="px-6 py-4 text-right font-mono text-emerald-400">$<?php echo number_format($p['bonus_amount'] ?: 0, 2); ?></td>
                                    <td class="px-6 py-4 text-right font-mono text-rose-400">$<?php echo number_format($p['deduction_amount'] ?: 0, 2); ?></td>
                                    <td class="px-6 py-4 text-right font-mono text-emerald-400 font-bold">$<?php echo number_format($p['net_salary'], 2); ?></td>
                                    <!-- Email Status -->
                                    <td class="px-6 py-4 text-center">
                                        <?php if ($emailStatus): ?>
                                            <?php if ($emailStatus['status'] === 'sent'): ?>
                                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                                    <i class="fa-solid fa-check text-[8px]"></i> Sent
                                                </span>
                                            <?php elseif ($emailStatus['status'] === 'failed'): ?>
                                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold bg-red-500/20 text-red-400 border border-red-500/30">
                                                    <i class="fa-solid fa-xmark text-[8px]"></i> Failed
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30">
                                                    <i class="fa-solid fa-clock text-[8px]"></i> Pending
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-[10px] text-zinc-500">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Send / Resend -->
                                    <td class="px-6 py-4 text-center">
                                        <?php if (!empty($p['email']) && filter_var($p['email'], FILTER_VALIDATE_EMAIL)): ?>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="payroll_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" name="send_email" onclick="return confirm('<?php echo ($emailStatus && $emailStatus['status'] === 'sent') ? 'Resend' : 'Send'; ?> salary slip to <?php echo htmlspecialchars(addslashes($p['email'])); ?>?')" class="text-xs <?php echo ($emailStatus && $emailStatus['status'] === 'failed') ? 'bg-amber-600 hover:bg-amber-700' : 'bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700'; ?> text-white font-semibold px-3 py-1.5 rounded-lg transition flex items-center gap-1 mx-auto shadow-sm">
                                                <i class="fa-solid <?php echo ($emailStatus && ($emailStatus['status'] === 'sent' || $emailStatus['status'] === 'failed')) ? 'fa-rotate-right' : 'fa-envelope'; ?>"></i>
                                                <?php echo ($emailStatus && ($emailStatus['status'] === 'sent' || $emailStatus['status'] === 'failed')) ? 'Resend' : 'Send'; ?>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-zinc-600 text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Email History Section -->
                <?php
                $historyStmt = $conn->prepare("
                    SELECT el.*, e.name as emp_name, e.employee_code
                    FROM email_logs el
                    JOIN payrolls p ON el.payroll_id = p.id
                    JOIN employee e ON el.employee_id = e.id
                    WHERE p.payroll_month = ? AND p.payroll_year = ?
                    ORDER BY el.created_at DESC
                ");
                $historyStmt->bind_param('ii', $selected_month, $selected_year);
                $historyStmt->execute();
                $historyData = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $historyStmt->close();
                ?>
                <div class="glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in-up stagger-3 mt-6" x-data="{ open: false }">
                    <button @click="open = !open" class="w-full p-6 border-b border-white/[0.06] flex items-center justify-between text-left">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-clock-rotate-left text-violet-400"></i>
                            <h2 class="text-lg font-bold text-white">Email History</h2>
                            <span class="text-xs text-zinc-500">(<?php echo count($historyData); ?> records)</span>
                        </div>
                        <i class="fa-solid fa-chevron-down text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                    </button>
                    <div x-show="open" x-transition class="overflow-x-auto">
                        <?php if (empty($historyData)): ?>
                            <div class="p-8 text-center text-zinc-500">No email history for this period.</div>
                        <?php else: ?>
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="px-6 py-3">Employee</th>
                                    <th class="px-6 py-3">Recipient</th>
                                    <th class="px-6 py-3">Subject</th>
                                    <th class="px-6 py-3 text-center">Status</th>
                                    <th class="px-6 py-3">Sent At</th>
                                    <th class="px-6 py-3">Error</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06]">
                                <?php foreach ($historyData as $h): ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-3 font-medium text-white"><?php echo htmlspecialchars($h['emp_name']); ?> <span class="text-zinc-500 text-xs">(<?php echo htmlspecialchars($h['employee_code']); ?>)</span></td>
                                    <td class="px-6 py-3 text-zinc-400 text-xs"><?php echo htmlspecialchars($h['recipient_email']); ?></td>
                                    <td class="px-6 py-3 text-zinc-400 text-xs"><?php echo htmlspecialchars($h['subject']); ?></td>
                                    <td class="px-6 py-3 text-center">
                                        <?php if ($h['status'] === 'sent'): ?>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold bg-emerald-500/20 text-emerald-400">Sent</span>
                                        <?php elseif ($h['status'] === 'failed'): ?>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold bg-red-500/20 text-red-400">Failed</span>
                                        <?php else: ?>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold bg-amber-500/20 text-amber-400">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3 text-zinc-500 text-xs"><?php echo $h['sent_at'] ? date('M d, Y H:i', strtotime($h['sent_at'])) : '-'; ?></td>
                                    <td class="px-6 py-3 text-rose-400 text-xs max-w-[200px] truncate" title="<?php echo htmlspecialchars($h['error_message'] ?? ''); ?>"><?php echo htmlspecialchars($h['error_message'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

                <?php else: ?>
                <div class="glass-strong rounded-2xl p-12 text-center animate-fade-in-up">
                    <i class="fa-solid fa-file-invoice text-4xl text-zinc-600 mb-3 block"></i>
                    <p class="text-zinc-400 font-medium">No payroll records for <?php echo $month_name . ' ' . $selected_year; ?></p>
                    <p class="text-zinc-500 text-sm mt-1">Run payroll first from the <a href="payroll.php" class="text-violet-400 hover:text-violet-300 font-semibold">Payroll</a> page.</p>
                </div>
                <?php endif; ?>
            </div>
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
