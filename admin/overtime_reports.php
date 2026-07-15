<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');
$department_id = $_GET['department_id'] ?? '';
$employee_id = $_GET['employee_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$request_type_filter = $_GET['request_type'] ?? '';
$export = $_GET['export'] ?? '';

$has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;

$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

$employees = [];
if ($department_id) {
    $emp_stmt = $conn->prepare("SELECT id, name, employee_code FROM employee WHERE department_id = ? AND status = 'active' ORDER BY name");
    $emp_stmt->bind_param('i', $department_id);
    $emp_stmt->execute();
    $employees = $emp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $emp_stmt->close();
}

$records = get_overtime_report_data($conn, $from_date, $to_date, $department_id ? (int)$department_id : null, $employee_id ? (int)$employee_id : null, $status_filter ?: null, $request_type_filter ?: null);

// Calculate stats
$total_hours = 0; $approved_hours = 0; $pending_hours = 0; $total_pay = 0;
foreach ($records as $r) {
    $total_hours += (float)$r['total_hours'];
    if ($r['status'] === 'Approved') {
        $approved_hours += (float)$r['total_hours'];
        if (isset($r['ot_pay'])) $total_pay += (float)$r['ot_pay'];
    }
    if ($r['status'] === 'Pending') $pending_hours += (float)$r['total_hours'];
}

// Handle export
if ($export && in_array($export, ['csv', 'excel'])) {
    $headers = ['Employee Name', 'Employee Code', 'Department', 'Position', 'OT Date', 'Start Time', 'End Time', 'Hours', 'Type', 'Request Type', 'OT Rate', 'OT Pay', 'Reason', 'Status', 'Submitted'];
    $data = [];
    foreach ($records as $r) {
        $data[] = [
            'employee_name' => $r['employee_name'],
            'employee_code' => $r['employee_code'],
            'department_name' => $r['department_name'] ?? '-',
            'position_name' => $r['position_name'] ?? '-',
            'ot_date' => $r['ot_date'],
            'start_time' => $r['start_time'],
            'end_time' => $r['end_time'],
            'total_hours' => $r['total_hours'] . 'h',
            'ot_type' => $r['ot_type'] ?? detect_overtime_type($conn, $r['ot_date']),
            'request_type' => $r['request_type'] ?? '-',
            'ot_rate' => isset($r['ot_rate']) ? $r['ot_rate'] : '-',
            'ot_pay' => isset($r['ot_pay']) && $r['ot_pay'] > 0 ? '$' . number_format($r['ot_pay'], 2) : '-',
            'reason' => $r['reason'],
            'status' => $r['status'],
            'created_at' => $r['created_at'],
        ];
    }
    if ($export === 'csv') {
        export_to_csv($data, $headers, 'overtime_report_' . $from_date . '_to_' . $to_date . '.csv');
    } else {
        export_to_excel($data, $headers, 'overtime_report_' . $from_date . '_to_' . $to_date . '.xls');
    }
    exit;
}

if ($export === 'pdf') {
    require_once '../config/dompdf_generator.php';
    $html = '<style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#333}table{width:100%;border-collapse:collapse}th,td{padding:6px 8px;border:1px solid #ddd;text-align:left}th{background:#f3f4f6;font-size:10px;text-transform:uppercase}.summary{display:flex;gap:20px;margin-bottom:16px}.summary-item{text-align:center}.summary-item .value{font-size:18px;font-weight:bold}.summary-item .label{font-size:10px;color:#666}</style>';
    $html .= '<h2>Overtime Report: ' . date('M d, Y', strtotime($from_date)) . ' - ' . date('M d, Y', strtotime($to_date)) . '</h2>';
    $html .= '<div class="summary">';
    $html .= '<div class="summary-item"><div class="value">' . number_format($total_hours, 1) . 'h</div><div class="label">Total Hours</div></div>';
    $html .= '<div class="summary-item"><div class="value">' . number_format($approved_hours, 1) . 'h</div><div class="label">Approved</div></div>';
    $html .= '<div class="summary-item"><div class="value">$' . number_format($total_pay, 2) . '</div><div class="label">Total Pay</div></div>';
    $html .= '<div class="summary-item"><div class="value">' . count($records) . '</div><div class="label">Requests</div></div></div>';
    $html .= '<table><thead><tr><th>Employee</th><th>Date</th><th>Hours</th><th>Type</th><th>Pay</th><th>Status</th></tr></thead><tbody>';
    foreach ($records as $r) {
        $type = $r['ot_type'] ?? detect_overtime_type($conn, $r['ot_date']);
        $pay = isset($r['ot_pay']) && $r['ot_pay'] > 0 ? '$' . number_format($r['ot_pay'], 2) : '-';
        $html .= "<tr><td>{$r['employee_name']} ({$r['employee_code']})</td><td>{$r['ot_date']}</td><td>{$r['total_hours']}h</td><td>$type</td><td>$pay</td><td>{$r['status']}</td></tr>";
    }
    $html .= '</tbody></table>';
    $html .= '<p style="margin-top:12px;font-size:9px;color:#999;">Generated on ' . date('Y-m-d H:i:s') . '</p>';
    generate_pdf($html, 'overtime_report_' . $from_date . '_to_' . $to_date . '.pdf');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Overtime Reports</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ openFilters: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Overtime Reports";
            $page_subtitle = "View, filter, and export overtime records.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-2 bg-white/[0.06] rounded-xl px-3 py-1.5 border border-white/[0.06]">
                <i class="fa-solid fa-calendar text-zinc-500 text-xs"></i>
                <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="bg-transparent border-0 text-white text-xs p-1 focus:outline-none w-28">
                <span class="text-zinc-600">-</span>
                <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="bg-transparent border-0 text-white text-xs p-1 focus:outline-none w-28">
            </div>
            <select name="department_id" class="bg-white/[0.06] border border-white/[0.06] text-white text-xs rounded-xl px-3 py-2 focus:outline-none" onchange="this.form.submit()">
                <option value="">All Depts</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?php echo $d['id']; ?>" <?php echo $department_id == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($employees)): ?>
            <select name="employee_id" class="bg-white/[0.06] border border-white/[0.06] text-white text-xs rounded-xl px-3 py-2 focus:outline-none">
                <option value="">All Employees</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?php echo $emp['id']; ?>" <?php echo $employee_id == $emp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($emp['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="status" class="bg-white/[0.06] border border-white/[0.06] text-white text-xs rounded-xl px-3 py-2 focus:outline-none">
                <option value="">All Status</option>
                <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="Rejected" <?php echo $status_filter == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
            <select name="request_type" class="bg-white/[0.06] border border-white/[0.06] text-white text-xs rounded-xl px-3 py-2 focus:outline-none">
                <option value="">All Types</option>
                <option value="employee_request" <?php echo $request_type_filter == 'employee_request' ? 'selected' : ''; ?>>Employee Request</option>
                <option value="admin_assignment" <?php echo $request_type_filter == 'admin_assignment' ? 'selected' : ''; ?>>Admin Assignment</option>
            </select>
            <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs px-4 py-2 shadow-sm transition flex items-center gap-1.5">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
            <div class="flex gap-1 ml-2">
                <a href="?<?php echo $_SERVER['QUERY_STRING']; ?>&export=csv" class="rounded-xl bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-400 px-3 py-2 text-xs transition"><i class="fa-solid fa-file-csv"></i></a>
                <a href="?<?php echo $_SERVER['QUERY_STRING']; ?>&export=excel" class="rounded-xl bg-green-600/20 hover:bg-green-600/30 text-green-400 px-3 py-2 text-xs transition"><i class="fa-solid fa-file-excel"></i></a>
                <a href="?<?php echo $_SERVER['QUERY_STRING']; ?>&export=pdf" class="rounded-xl bg-rose-600/20 hover:bg-rose-600/30 text-rose-400 px-3 py-2 text-xs transition"><i class="fa-solid fa-file-pdf"></i></a>
            </div>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto space-y-6">

            <!-- Summary -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Hours</span>
                    <p class="text-xl font-bold text-white"><?php echo number_format($total_hours, 1); ?>h</p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Approved</span>
                    <p class="text-xl font-bold text-emerald-400"><?php echo number_format($approved_hours, 1); ?>h</p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Pending</span>
                    <p class="text-xl font-bold text-amber-400"><?php echo number_format($pending_hours, 1); ?>h</p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Total Pay</span>
                    <p class="text-xl font-bold text-purple-400">$<?php echo number_format($total_pay, 2); ?></p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Requests</span>
                    <p class="text-xl font-bold text-blue-400"><?php echo count($records); ?></p>
                </div>
            </section>

            <!-- Records Table -->
            <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-5 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white"><i class="fa-solid fa-clock text-blue-400 mr-2"></i>Overtime Records (<?php echo count($records); ?>)</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-5 py-3">Employee</th>
                                <th class="px-5 py-3">Code</th>
                                <th class="px-5 py-3">Dept</th>
                                <th class="px-5 py-3">Date</th>
                                <th class="px-5 py-3">Time</th>
                                <th class="px-5 py-3">Hours</th>
                                <th class="px-5 py-3">Type</th>
                                <th class="px-5 py-3">Source</th>
                                <th class="px-5 py-3">Rate</th>
                                <th class="px-5 py-3">Pay</th>
                                <th class="px-5 py-3">Reason</th>
                                <th class="px-5 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.04]">
                            <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="12" class="px-5 py-12 text-center text-zinc-500">
                                    <p class="text-lg mb-1">No records found for the selected filters.</p>
                                    <p class="text-xs text-zinc-600">Try adjusting the date range or filters.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($records as $r): ?>
                                <?php
                                    $type = $has_ot_type && $r['ot_type']
                                        ? $r['ot_type']
                                        : detect_overtime_type($conn, $r['ot_date']);
                                    $rate_display = isset($r['ot_rate']) ? '×' . number_format($r['ot_rate'], 2) : '-';
                                    $pay_display = isset($r['ot_pay']) && $r['ot_pay'] > 0 ? '$' . number_format($r['ot_pay'], 2) : '-';
                                ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-5 py-3 font-medium text-white"><?php echo htmlspecialchars($r['employee_name']); ?></td>
                                    <td class="px-5 py-3 text-zinc-400 font-mono text-xs"><?php echo htmlspecialchars($r['employee_code']); ?></td>
                                    <td class="px-5 py-3 text-zinc-400 text-xs"><?php echo htmlspecialchars($r['department_name'] ?? '-'); ?></td>
                                    <td class="px-5 py-3"><?php echo date('M d, Y', strtotime($r['ot_date'])); ?></td>
                                    <td class="px-5 py-3 font-mono text-xs text-zinc-300"><?php echo date('h:i A', strtotime($r['start_time'])); ?> - <?php echo date('h:i A', strtotime($r['end_time'])); ?></td>
                                    <td class="px-5 py-3 font-semibold"><?php echo $r['total_hours']; ?>h</td>
                                    <td class="px-5 py-3"><?php echo get_overtime_type_badge($type); ?></td>
                                    <td class="px-5 py-3">
                                        <?php if (isset($r['request_type']) && $r['request_type'] === 'admin_assignment'): ?>
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold bg-purple-500/20 text-purple-400">Admin</span>
                                        <?php else: ?>
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold bg-blue-500/20 text-blue-400">Employee</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 font-mono text-xs text-zinc-400"><?php echo $rate_display; ?></td>
                                    <td class="px-5 py-3 font-mono text-sm <?php echo $pay_display !== '-' ? 'text-emerald-400 font-semibold' : 'text-zinc-500'; ?>"><?php echo $pay_display; ?></td>
                                    <td class="px-5 py-3 text-zinc-400 max-w-[120px] truncate text-xs" title="<?php echo htmlspecialchars($r['reason']); ?>"><?php echo htmlspecialchars($r['reason']); ?></td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold
                                            <?php echo $r['status'] == 'Approved' ? 'bg-emerald-500/20 text-emerald-400' : ''; ?>
                                            <?php echo $r['status'] == 'Rejected' ? 'bg-red-500/20 text-red-400' : ''; ?>
                                            <?php echo $r['status'] == 'Pending' ? 'bg-amber-500/20 text-amber-400' : ''; ?>
                                        "><?php echo $r['status']; ?></span>
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
