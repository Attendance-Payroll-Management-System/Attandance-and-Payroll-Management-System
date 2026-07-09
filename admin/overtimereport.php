<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$status_filter = $_GET['status'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');

$where = "1=1";
$params = [];
$types = '';

if ($status_filter) {
    $where .= " AND otr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($from_date && $to_date) {
    $where .= " AND otr.ot_date BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $types .= 'ss';
}

$sql = "SELECT otr.*, e.name, e.employee_code, d.department_name
        FROM overtime_requests otr
        JOIN employee e ON otr.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE $where
        ORDER BY otr.ot_date DESC";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_hours = 0; $total_approved_hours = 0;
foreach ($records as $r) {
    $total_hours += $r['total_hours'];
    if ($r['status'] == 'Approved') $total_approved_hours += $r['total_hours'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Overtime Report</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Overtime Report";
            $page_subtitle = "View and filter overtime records across employees.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
            <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
            <select name="status" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                <option value="">All Status</option>
                <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="Rejected" <?php echo $status_filter == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total OT Hours</span>
                    <p class="text-2xl font-bold text-white"><?php echo number_format($total_hours, 1); ?>h</p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Approved Hours</span>
                    <p class="text-2xl font-bold text-emerald-400"><?php echo number_format($total_approved_hours, 1); ?>h</p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Requests</span>
                    <p class="text-2xl font-bold text-blue-400"><?php echo count($records); ?></p>
                </div>
            </section>

            <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-clock text-blue-400 mr-2"></i>Overtime Records</h2>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo count($records); ?> records</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4">Code</th>
                                <th class="px-6 py-4">Department</th>
                                <th class="px-6 py-4">OT Date</th>
                                <th class="px-6 py-4">Time</th>
                                <th class="px-6 py-4">Hours</th>
                                <th class="px-6 py-4">Reason</th>
                                <th class="px-6 py-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-zinc-500">
                                    <p class="text-lg mb-2">No overtime records found for the selected filters.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($records as $r): ?>
                                <?php
                                    $badge = match($r['status']) {
                                        'Approved' => 'bg-emerald-500/20 text-emerald-400',
                                        'Rejected' => 'bg-red-500/20 text-red-400',
                                        'Pending' => 'bg-amber-500/20 text-amber-400',
                                        default => 'bg-white/10 text-zinc-300'
                                    };
                                ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-4 font-medium text-white"><?php echo htmlspecialchars($r['name']); ?></td>
                                    <td class="px-6 py-4 text-zinc-400 font-mono text-xs"><?php echo htmlspecialchars($r['employee_code']); ?></td>
                                    <td class="px-6 py-4 text-zinc-400"><?php echo htmlspecialchars($r['department_name'] ?? '-'); ?></td>
                                    <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($r['ot_date'])); ?></td>
                                    <td class="px-6 py-4 font-mono text-sm"><?php echo date('h:i A', strtotime($r['start_time'])); ?> - <?php echo date('h:i A', strtotime($r['end_time'])); ?></td>
                                    <td class="px-6 py-4 font-semibold"><?php echo $r['total_hours']; ?>h</td>
                                    <td class="px-6 py-4 text-zinc-400 max-w-[200px] truncate text-sm" title="<?php echo htmlspecialchars($r['reason']); ?>"><?php echo htmlspecialchars($r['reason']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo $badge; ?>"><?php echo $r['status']; ?></span>
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
