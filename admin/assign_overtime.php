<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';
require_once '../config/notifications.php';

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn);
$notifications = get_notifications($conn, null, 10);

$has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;
$has_assigned_by = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'assigned_by_id'")->num_rows > 0;

$employees = $conn->query("SELECT e.id, e.name, e.employee_code, e.department_id, d.department_name, p.position_name FROM employee e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN positions p ON e.position_id = p.id WHERE e.status = 'active' ORDER BY e.name")->fetch_all(MYSQLI_ASSOC);

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'];
$admin_department = $_SESSION['admin_department'] ?? '';
$admin_position = $_SESSION['admin_position'] ?? '';

// Build employee -> department mapping for JS
$emp_dept_map = [];
foreach ($employees as $emp) {
    $emp_dept_map[$emp['id']] = intval($emp['department_id'] ?? 0);
}

// Fetch all managers (position containing 'manager' or 'lead', or first employee per dept)
$managers = $conn->query("
    SELECT
        e.id AS manager_id,
        e.name AS manager_name,
        e.department_id,
        d.department_name,
        p.position_name AS manager_position
    FROM employee e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN positions p ON e.position_id = p.id
    WHERE e.status = 'active'
    AND e.department_id IS NOT NULL
    AND (
        LOWER(p.position_name) LIKE '%manager%'
        OR LOWER(p.position_name) LIKE '%lead%'
        OR e.id IN (
            SELECT MIN(e2.id) FROM employee e2
            WHERE e2.status = 'active' AND e2.department_id IS NOT NULL
            GROUP BY e2.department_id
        )
    )
    ORDER BY d.department_name, e.name
")->fetch_all(MYSQLI_ASSOC);

// Build department -> managers mapping for JS
$dept_managers_map = [];
foreach ($managers as $m) {
    $dept_managers_map[$m['department_id']][] = [
        'id' => $m['manager_id'],
        'name' => $m['manager_name'],
        'department' => $m['department_name'],
        'position' => $m['manager_position'] ?? '-'
    ];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_ot'])) {
    $employee_id = $_POST['employee_id'] ?? 0;
    $ot_date = $_POST['ot_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (!$employee_id || empty($ot_date) || empty($start_time) || empty($end_time)) {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $message = 'End time must be after start time.';
        $message_type = 'error';
    } else {
        $start_ts = strtotime($start_time);
        $end_ts = strtotime($end_time);
        $total_hours = round(($end_ts - $start_ts) / 3600, 2);

        $emp = $conn->prepare("SELECT name FROM employee WHERE id = ?");
        $emp->bind_param('i', $employee_id);
        $emp->execute();
        $emp_name = $emp->get_result()->fetch_assoc()['name'] ?? 'Employee';
        $emp->close();

        // Use the selected manager from the dropdown
        $assigned_by_id   = intval($_POST['assigned_by_id'] ?? 0);
        $assigned_by_name = $admin_name;
        $assigned_by_dept = $admin_department;
        $assigned_by_pos  = $admin_position;

        if ($assigned_by_id > 0 && $has_assigned_by) {
            $mgr = $conn->prepare("
                SELECT e.name AS mgr_name, d.department_name, p.position_name AS mgr_position
                FROM employee e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN positions p ON e.position_id = p.id
                WHERE e.id = ? AND e.status = 'active'
            ");
            $mgr->bind_param('i', $assigned_by_id);
            $mgr->execute();
            $mgr_row = $mgr->get_result()->fetch_assoc();
            $mgr->close();

            if ($mgr_row) {
                $assigned_by_name = $mgr_row['mgr_name'];
                $assigned_by_dept = $mgr_row['department_name'] ?? $admin_department;
                $assigned_by_pos  = $mgr_row['mgr_position'] ?? $admin_position;
            }
        }

        if ($has_assigned_by) {
            $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason, source, status, assigned_by_id, assigned_by_name, assigned_by_department, assigned_by_position, assigned_at) VALUES (?, ?, ?, ?, ?, ?, 'admin_assigned', 'Pending', ?, ?, ?, ?, NOW())");
            $stmt->bind_param('isssdsisss', $employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason, $assigned_by_id, $assigned_by_name, $assigned_by_dept, $assigned_by_pos);
        } elseif ($has_source) {
            $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason, source, status) VALUES (?, ?, ?, ?, ?, ?, 'admin_assigned', 'Pending')");
            $stmt->bind_param('isssds', $employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason);
        } else {
            $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->bind_param('isssds', $employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason);
        }
        if ($stmt->execute()) {
            create_notification($conn, $employee_id, 'ot_assigned', "Overtime has been assigned to you by $assigned_by_name ($assigned_by_pos, $assigned_by_dept). OT Date: $ot_date (" . number_format($total_hours, 1) . "h).", 'overtimerequest.php');
            $message = "Overtime assigned to $emp_name successfully.";
            $message_type = 'success';
        } else {
            $message = 'Error assigning overtime.';
            $message_type = 'error';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Assign Overtime</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Assign Overtime";
        $page_subtitle = "Assign overtime work to employees.";
        include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$has_source): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border bg-amber-500/20 border-amber-500/30 text-amber-400">
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                    <strong>Migration needed:</strong> Run <code class="bg-amber-500/20 px-1 rounded text-amber-400">config/migration_overtime_source.sql</code> to enable full assignment tracking. Assignments will work without it.
                </div>
            <?php endif; ?>

            <div class="max-w-2xl">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                    <h2 class="text-lg font-bold text-white mb-6"><i class="fa-solid fa-clock text-blue-400 mr-2"></i>New Overtime Assignment</h2>
                    <form method="POST" class="space-y-5 text-zinc-300">

                        <?php echo csrf_field(); ?>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Employee</label>
                            <select name="employee_id" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name'] . ' (' . $emp['employee_code'] . ') - ' . ($emp['department_name'] ?? 'N/A') . ' / ' . ($emp['position_name'] ?? 'N/A')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($has_assigned_by): ?>
                            <div class="rounded-xl border border-blue-400/20 bg-blue-500/10 p-4">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-blue-400 mb-2"><i class="fa-solid fa-user-check mr-1"></i>Assignment Info</h3>
                                <div>
                                    <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Assigned By (Department Manager)</label>
                                    <select name="assigned_by_id" id="assigned_by_select" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                        <option value="">-- Select Manager --</option>
                                        <?php
                                        $current_dept = '';
                                        foreach ($managers as $m):
                                            if ($m['department_name'] !== $current_dept):
                                                if ($current_dept !== '') echo '</optgroup>';
                                                $current_dept = $m['department_name'];
                                                echo '<optgroup label="' . htmlspecialchars($current_dept) . '">';
                                            endif;
                                        ?>
                                            <option value="<?php echo $m['manager_id']; ?>" data-dept="<?php echo $m['department_id']; ?>" data-dept-name="<?php echo htmlspecialchars($m['department_name']); ?>" data-position="<?php echo htmlspecialchars($m['manager_position'] ?? '-'); ?>">
                                                <?php echo htmlspecialchars($m['manager_name'] . ' - ' . ($m['manager_position'] ?? 'N/A')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($current_dept !== '') echo '</optgroup>'; ?>
                                    </select>
                                </div>
                                <p class="text-xs text-zinc-500 mt-2"><i class="fa-solid fa-info-circle mr-1"></i>Select an employee first - the department manager will be auto-selected</p>
                            </div>
                        <?php endif; ?>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">OT Date</label>
                            <input type="date" name="ot_date" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Start Time</label>
                                <input type="time" name="start_time" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">End Time</label>
                                <input type="time" name="end_time" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Reason / Instructions</label>
                            <textarea name="reason" rows="3" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30 resize-none" placeholder="Describe the overtime task..."></textarea>
                        </div>



                        <button type="submit" name="assign_ot" class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-clock"></i> Assign Overtime
                        </button>
                    </form>
                </div>
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

    <?php if ($has_assigned_by): ?>
        <script>
            (function() {
                var empDeptMap = <?php echo json_encode($emp_dept_map); ?>;
                var deptManagersMap = <?php echo json_encode($dept_managers_map); ?>;
                var managerSelect = document.getElementById('assigned_by_select');
                var empSel = document.querySelector('select[name="employee_id"]');

                function selectManagerForDept(deptId) {
                    if (!deptId || !deptManagersMap[deptId]) return false;
                    var managers = deptManagersMap[deptId];
                    // Select the first manager for the department
                    var targetVal = String(managers[0].id);
                    for (var i = 0; i < managerSelect.options.length; i++) {
                        if (managerSelect.options[i].value === targetVal) {
                            managerSelect.selectedIndex = i;
                            return true;
                        }
                    }
                    return false;
                }

                empSel.addEventListener('change', function() {
                    var empId = this.value;
                    if (!empId) {
                        managerSelect.selectedIndex = 0;
                        return;
                    }
                    var deptId = empDeptMap[empId];
                    if (!deptId) {
                        managerSelect.selectedIndex = 0;
                        return;
                    }
                    if (!selectManagerForDept(deptId)) {
                        managerSelect.selectedIndex = 0;
                    }
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>