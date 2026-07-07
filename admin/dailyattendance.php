<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$selected_date = $_GET['date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? '';

$where = "a.attendance_date = ?";
$params = [$selected_date];
$types = 's';

if ($status_filter) {
    $where .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$sql = "SELECT a.*, e.name, e.employee_code, d.department_name
        FROM attendance a
        JOIN employee e ON a.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE $where
        ORDER BY e.name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary_stmt = $conn->prepare("SELECT a.status, COUNT(*) as cnt FROM attendance a WHERE a.attendance_date = ? GROUP BY a.status");
$summary_stmt->bind_param('s', $selected_date);
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$summary_stmt->close();

$total_present = 0;
$total_absent = 0;
$total_late = 0;
$total_leave = 0;
$total_awol = 0;
$total_half_absent = 0;
$total_full_absent = 0;
$total_holiday = 0;
$total_weekend = 0;
foreach ($summary as $s) {
    if ($s['status'] == 'present') $total_present = $s['cnt'];
    if ($s['status'] == 'absent') $total_absent = $s['cnt'];
    if ($s['status'] == 'late') $total_late = $s['cnt'];
    if ($s['status'] == 'leave') $total_leave = $s['cnt'];
    if ($s['status'] == 'awol') $total_awol = $s['cnt'];
    if ($s['status'] == 'half_absent') $total_half_absent = $s['cnt'];
    if ($s['status'] == 'full_absent') $total_full_absent = $s['cnt'];
    if ($s['status'] == 'public_holiday') $total_holiday = $s['cnt'];
    if ($s['status'] == 'weekend') $total_weekend = $s['cnt'];
}
$total_emp = $total_present + $total_absent + $total_late + $total_leave + $total_awol + $total_half_absent + $total_full_absent + $total_holiday + $total_weekend;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Daily Attendance</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Daily Attendance";
            $page_subtitle = "View attendance records grouped by employee and status.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <input type="date" name="date" value="<?php echo $selected_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
            <select name="status" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                <option value="">All Status</option>
                <option value="present" <?php echo $status_filter == 'present' ? 'selected' : ''; ?>>Present</option>
                <option value="late" <?php echo $status_filter == 'late' ? 'selected' : ''; ?>>Late</option>
                <option value="leave" <?php echo $status_filter == 'leave' ? 'selected' : ''; ?>>Approved Leave</option>
                <option value="half_absent" <?php echo $status_filter == 'half_absent' ? 'selected' : ''; ?>>Half-Day Absent</option>
                <option value="full_absent" <?php echo $status_filter == 'full_absent' ? 'selected' : ''; ?>>Full-Day Absent</option>
                <option value="awol" <?php echo $status_filter == 'awol' ? 'selected' : ''; ?>>AWOL</option>
                <option value="absent" <?php echo $status_filter == 'absent' ? 'selected' : ''; ?>>Absent</option>
                <option value="public_holiday" <?php echo $status_filter == 'public_holiday' ? 'selected' : ''; ?>>Public Holiday</option>
                <option value="weekend" <?php echo $status_filter == 'weekend' ? 'selected' : ''; ?>>Weekend</option>
            </select>
            <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> View
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-500">Total Employees</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_emp; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-400">Present</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_present; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-amber-400">Late</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_late; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-blue-400">On Leave</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_leave; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-red-500">AWOL</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_awol; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-rose-400">Full-Day Absent</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_full_absent; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-orange-400">Half-Day Absent</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_half_absent; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-pink-400">Public Holiday</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_holiday; ?></p>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <span class="text-xs font-bold uppercase tracking-wider text-purple-400">Weekend</span>
                    <p class="text-2xl font-bold text-white"><?php echo $total_weekend; ?></p>
                </div>
            </section>

            <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-calendar-check text-violet-400 mr-2"></i>Attendance &mdash; <?php echo date('F d, Y', strtotime($selected_date)); ?></h2>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo count($records); ?> records</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4">Code</th>
                                <th class="px-6 py-4">Department</th>
                                <th class="px-6 py-4">Check In</th>
                                <th class="px-6 py-4">Check Out</th>
                                <th class="px-6 py-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-zinc-500">
                                        <p class="text-lg mb-2">No attendance records for <?php echo $selected_date; ?></p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $r): ?>
                                    <tr class="hover:bg-white/[0.02] transition">
                                        <td class="px-6 py-4 font-medium text-white"><?php echo htmlspecialchars($r['name']); ?></td>
                                        <td class="px-6 py-4 text-zinc-400 font-mono text-xs"><?php echo htmlspecialchars($r['employee_code']); ?></td>
                                        <td class="px-6 py-4 text-zinc-400"><?php echo htmlspecialchars($r['department_name'] ?? '-'); ?></td>
                                        <td class="px-6 py-4 font-mono text-sm"><?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '-'; ?></td>
                                        <td class="px-6 py-4 font-mono text-sm"><?php echo $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '-'; ?></td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?php echo get_attendance_status_badge_class($r['status']); ?>"><?php echo get_attendance_status_label($r['status']); ?></span>
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
