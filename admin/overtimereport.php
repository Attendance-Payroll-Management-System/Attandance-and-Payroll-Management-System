<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

// Filters
$status_filter = $_GET['status'] ?? '';
$dept_filter = (int)($_GET['department'] ?? 0);
$view_mode = $_GET['view'] ?? 'monthly';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

// Weekly view: auto-set dates
if ($view_mode === 'weekly') {
    $week_start = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
    $from_date = $week_start;
    $to_date = date('Y-m-d', strtotime($week_start . ' +6 days'));
}

// Build query
$where = "1=1";
$params = [];
$types = '';

if ($status_filter) {
    $where .= " AND otr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($dept_filter > 0) {
    $where .= " AND e.department_id = ?";
    $params[] = $dept_filter;
    $types .= 'i';
}
if ($from_date && $to_date) {
    $where .= " AND otr.ot_date BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $types .= 'ss';
}

// Count
$count_sql = "SELECT COUNT(*) as total FROM overtime_requests otr JOIN employee e ON otr.employee_id = e.id WHERE $where";
$count_stmt = $conn->prepare($count_sql);
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_records = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = max(1, ceil($total_records / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Fetch with pagination
$sql = "SELECT otr.*, e.name as employee_name, e.employee_code, d.department_name, p.position_name
        FROM overtime_requests otr
        JOIN employee e ON otr.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions p ON e.position_id = p.id
        WHERE $where
        ORDER BY otr.ot_date DESC
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stats
$total_hours = 0; $total_approved_hours = 0; $approved_count = 0; $pending_count = 0; $rejected_count = 0;
foreach ($records as $r) {
    $total_hours += $r['total_hours'];
    if ($r['status'] == 'Approved') { $total_approved_hours += $r['total_hours']; $approved_count++; }
    elseif ($r['status'] == 'Pending') $pending_count++;
    elseif ($r['status'] == 'Rejected') $rejected_count++;
}

// Departments for filter
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

// Build query string for pagination
$qs_params = array_filter([
    'status' => $status_filter ?: null,
    'department' => $dept_filter ?: null,
    'view' => $view_mode,
    'from_date' => $from_date,
    'to_date' => $to_date,
    'week_start' => $view_mode === 'weekly' ? $from_date : null,
]);
$query_string = http_build_query(array_filter($qs_params));

// Helper function
function get_ot_type_badge($type) {
    return match($type) {
        'working_day' => '<span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold bg-blue-500/15 text-blue-400">Working Day</span>',
        'weekend' => '<span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold bg-amber-500/15 text-amber-400">Weekend</span>',
        'holiday' => '<span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold bg-rose-500/15 text-rose-400">Holiday</span>',
        default => '<span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold bg-zinc-500/15 text-zinc-400">' . htmlspecialchars($type) . '</span>',
    };
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
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Overtime Report";
            $page_subtitle = "View overtime records by department, weekly or monthly.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
            
            <select name="department" class="bg-white/[0.06] border-white/10 text-white text-sm rounded-lg p-2.5">
                <option value="0">All Departments</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?php echo $d['id']; ?>" <?php echo $dept_filter == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <select name="status" class="bg-white/[0.06] border-white/10 text-white text-sm rounded-lg p-2.5">
                <option value="">All Status</option>
                <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Approved" <?php echo $status_filter == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="Rejected" <?php echo $status_filter == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>

            <?php if ($view_mode === 'weekly'): ?>
            <input type="date" name="week_start" value="<?php echo $from_date; ?>" class="bg-white/[0.06] border-white/10 text-white text-sm rounded-lg p-2.5">
            <span class="text-zinc-400 text-sm"><?php echo date('M d', strtotime($from_date)); ?> - <?php echo date('M d, Y', strtotime($to_date)); ?></span>
            <?php else: ?>
            <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="bg-white/[0.06] border-white/10 text-white text-sm rounded-lg p-2.5">
            <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="bg-white/[0.06] border-white/10 text-white text-sm rounded-lg p-2.5">
            <?php endif; ?>

            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        
        <main class="flex-1 p-8 overflow-y-auto">
            <!-- View Toggle -->
            <div class="flex items-center gap-2 mb-6">
                <a href="?<?php echo http_build_query(array_merge($qs_params, ['view' => 'monthly'])); ?>" class="px-4 py-2 rounded-lg text-sm font-semibold transition-all <?php echo $view_mode === 'monthly' ? 'bg-blue-500/20 text-blue-400' : 'bg-white/[0.06] text-zinc-400 hover:text-white'; ?>">
                    <i class="fa-solid fa-calendar mr-1"></i> Monthly
                </a>
                <a href="?<?php echo http_build_query(array_merge($qs_params, ['view' => 'weekly'])); ?>" class="px-4 py-2 rounded-lg text-sm font-semibold transition-all <?php echo $view_mode === 'weekly' ? 'bg-blue-500/20 text-blue-400' : 'bg-white/[0.06] text-zinc-400 hover:text-white'; ?>">
                    <i class="fa-solid fa-calendar-week mr-1"></i> Weekly
                </a>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="glass-strong rounded-xl p-4 text-center">
                    <p class="text-2xl font-bold text-white"><?php echo number_format($total_hours, 1); ?>h</p>
                    <p class="text-xs text-zinc-500 mt-1">Total Hours</p>
                </div>
                <div class="glass-strong rounded-xl p-4 text-center">
                    <p class="text-2xl font-bold text-emerald-400"><?php echo number_format($total_approved_hours, 1); ?>h</p>
                    <p class="text-xs text-zinc-500 mt-1">Approved</p>
                </div>
                <div class="glass-strong rounded-xl p-4 text-center">
                    <p class="text-2xl font-bold text-amber-400"><?php echo $pending_count; ?></p>
                    <p class="text-xs text-zinc-500 mt-1">Pending</p>
                </div>
                <div class="glass-strong rounded-xl p-4 text-center">
                    <p class="text-2xl font-bold text-rose-400"><?php echo $rejected_count; ?></p>
                    <p class="text-xs text-zinc-500 mt-1">Rejected</p>
                </div>
            </div>

            <!-- Table -->
            <div class="glass-strong rounded-2xl overflow-hidden">
                <div class="p-4 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white"><i class="fa-solid fa-list text-blue-400 mr-2"></i>Overtime Records</h2>
                    <span class="text-xs text-zinc-500"><?php echo $total_records; ?> records | Page <?php echo $page; ?>/<?php echo $total_pages; ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-zinc-500 text-xs uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-4 py-3">Employee</th>
                                <th class="px-4 py-3">Department</th>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Time</th>
                                <th class="px-4 py-3">Hours</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                            <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-zinc-500">No records found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($records as $r): ?>
                            <tr class="hover:bg-white/[0.02]">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-white"><?php echo htmlspecialchars($r['employee_name']); ?></div>
                                    <div class="text-xs text-zinc-500"><?php echo htmlspecialchars($r['employee_code']); ?></div>
                                </td>
                                <td class="px-4 py-3 text-zinc-400"><?php echo htmlspecialchars($r['department_name'] ?? '-'); ?></td>
                                <td class="px-4 py-3 font-mono text-xs"><?php echo date('M d, Y', strtotime($r['ot_date'])); ?></td>
                                <td class="px-4 py-3 font-mono text-xs"><?php echo date('h:i A', strtotime($r['start_time'])); ?> - <?php echo date('h:i A', strtotime($r['end_time'])); ?></td>
                                <td class="px-4 py-3 font-mono text-xs font-semibold"><?php echo number_format($r['total_hours'], 1); ?>h</td>
                                <td class="px-4 py-3"><?php echo get_ot_type_badge($r['ot_type'] ?? 'working_day'); ?></td>
                                <td class="px-4 py-3">
                                    <?php
                                    $badge_class = match($r['status']) {
                                        'Approved' => 'bg-emerald-500/20 text-emerald-400',
                                        'Rejected' => 'bg-rose-500/20 text-rose-400',
                                        'Pending' => 'bg-amber-500/20 text-amber-400',
                                        default => 'bg-zinc-500/20 text-zinc-400',
                                    };
                                    ?>
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $badge_class; ?>"><?php echo $r['status']; ?></span>
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-400 max-w-[200px] truncate" title="<?php echo htmlspecialchars($r['reason']); ?>"><?php echo htmlspecialchars($r['reason']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="px-4 py-3 border-t border-white/[0.06] flex items-center justify-between">
                    <span class="text-xs text-zinc-500">Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?></span>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $page - 1])); ?>" class="px-3 py-1.5 rounded-lg bg-white/[0.06] text-zinc-400 hover:text-white text-xs font-semibold transition-all">
                            <i class="fa-solid fa-chevron-left text-[10px]"></i>
                        </a>
                        <?php endif; ?>
                        <?php
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);
                        for ($i = $start_p; $i <= $end_p; $i++):
                        ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $i])); ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all <?php echo $i === $page ? 'bg-blue-500/20 text-blue-400' : 'bg-white/[0.06] text-zinc-400 hover:text-white'; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $page + 1])); ?>" class="px-3 py-1.5 rounded-lg bg-white/[0.06] text-zinc-400 hover:text-white text-xs font-semibold transition-all">
                            <i class="fa-solid fa-chevron-right text-[10px]"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
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
