<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$message = '';
$message_type = '';

// Pagination
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$per_page = in_array($per_page, [5, 10, 25, 50]) ? $per_page : 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Filters
$filter_dept = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$filter_position = isset($_GET['position']) ? (int)$_GET['position'] : 0;
$filter_status = isset($_GET['att_status']) ? $_GET['att_status'] : '';
$filter_employee = isset($_GET['employee']) ? (int)$_GET['employee'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle manual attendance add/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_manual' && isset($_POST['employee_id'], $_POST['attendance_date'], $_POST['check_in'])) {
        $emp_id = (int)$_POST['employee_id'];
        $att_date = $_POST['attendance_date'];
        $check_in = $_POST['check_in'] ?: null;
        $check_out = $_POST['check_out'] ?: null;
        $status = $_POST['status'] ?? 'present';
        $remarks = $_POST['remarks'] ?? '';

        // Check existing
        $existing = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
        $existing->bind_param('is', $emp_id, $att_date);
        $existing->execute();
        $existing->store_result();
        $exists = $existing->num_rows > 0;
        $existing->close();

        if ($exists) {
            $message = 'Attendance record already exists for this employee on this date.';
            $message_type = 'error';
        } else {
            $total_hours = null;
            if ($check_in && $check_out) {
                $total_hours = round((strtotime($check_out) - strtotime($check_in)) / 3600, 2);
            }
            $is_late = ($check_in && is_late_checkin($check_in)) ? 1 : 0;

            $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in, check_out, status, is_late, total_working_hours,remarks) VALUES (?, ?, ?, ?, ?, ?, ?,?d .)");
            $stmt->bind_param('issssids', $emp_id, $att_date, $check_in, $check_out, $status, $is_late, $total_hours, $remarks);
            if ($stmt->execute()) {
                $message = 'Manual attendance record added successfully.';
                $message_type = 'success';
                log_activity($conn, $_SESSION['admin_id'], 'add_manual_attendance', "Manual attendance added for employee #$emp_id on $att_date");
            } else {
                $message = 'Error: ' . $stmt->error;
                $message_type = 'error';
            }
            $stmt->close();
        }
    }

    if ($action === 'edit_attendance' && isset($_POST['att_id'])) {
        $att_id = (int)$_POST['att_id'];
        $check_in = $_POST['check_in'] ?: null;
        $check_out = $_POST['check_out'] ?: null;
        $status = $_POST['status'];
        $remarks = $_POST['remarks'] ?? '';

        $total_hours = null;
        if ($check_in && $check_out) {
            $total_hours = round((strtotime($check_out) - strtotime($check_in)) / 3600, 2);
        }
        $is_late = ($check_in && is_late_checkin($check_in)) ? 1 : 0;

        $stmt = $conn->prepare("UPDATE attendance SET check_in = ?, check_out = ?, status = ?, is_late = ?, total_working_hours = ?, remarks = ?, is_manual = 1 WHERE id = ?");
        $stmt->bind_param('sssidii', $check_in, $check_out, $status, $is_late, $total_hours, $remarks, $att_id);
        if ($stmt->execute()) {
            $message = 'Attendance record updated successfully.';
            $message_type = 'success';
            log_activity($conn, $_SESSION['admin_id'], 'edit_attendance', "Attendance #$att_id updated by admin");
        } else {
            $message = 'Error: ' . $stmt->error;
            $message_type = 'error';
        }
        $stmt->close();
    }

    if ($action === 'delete_attendance' && isset($_POST['att_id'])) {
        $att_id = (int)$_POST['att_id'];
        $stmt = $conn->prepare("DELETE FROM attendance WHERE id = ?");
        $stmt->bind_param('i', $att_id);
        if ($stmt->execute()) {
            $message = 'Attendance record deleted successfully.';
            $message_type = 'success';
            log_activity($conn, $_SESSION['admin_id'], 'delete_attendance', "Attendance #$att_id deleted by admin");
        } else {
            $message = 'Error: ' . $stmt->error;
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Build query
$where = [];
$params = [];
$types = '';

if ($filter_dept > 0) {
    $where[] = 'e.department_id = ?';
    $params[] = $filter_dept;
    $types .= 'i';
}
if ($filter_position > 0) {
    $where[] = 'e.position_id = ?';
    $params[] = $filter_position;
    $types .= 'i';
}
if ($filter_employee > 0) {
    $where[] = 'a.employee_id = ?';
    $params[] = $filter_employee;
    $types .= 'i';
}
if ($filter_status !== '') {
    $where[] = 'a.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}
if ($date_from && $date_to) {
    $where[] = 'a.attendance_date BETWEEN ? AND ?';
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= 'ss';
}
if ($search !== '') {
    $where[] = '(e.name LIKE ? OR e.employee_code LIKE ?)';
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

$where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM attendance a JOIN employee e ON a.employee_id = e.id $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, ceil($total_records / $per_page));

// Fetch records
$sql = "SELECT a.*, e.name as employee_name, e.employee_code, d.department_name 
        FROM attendance a 
        JOIN employee e ON a.employee_id = e.id 
        LEFT JOIN departments d ON e.department_id = d.id 
        $where_clause 
        ORDER BY a.attendance_date DESC, a.check_in DESC 
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get departments and positions for filters
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);
$positions = $conn->query("SELECT id, position_name FROM positions ORDER BY position_name")->fetch_all(MYSQLI_ASSOC);
$employees = $conn->query("SELECT id, name, employee_code FROM employee WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$statuses = ['present', 'late', 'half_day', 'paid_leave', 'unpaid_leave', 'absent', 'awol', 'weekend', 'public_holiday'];

// For add manual modal
$active_employees = $conn->query("SELECT id, name, employee_code FROM employee WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Export logic
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $all_sql = "SELECT a.attendance_date, e.employee_code, e.name as employee_name, d.department_name, 
                       a.check_in, a.check_out, a.total_working_hours, a.status, a.is_late, a.remarks
                FROM attendance a 
                JOIN employee e ON a.employee_id = e.id 
                LEFT JOIN departments d ON e.department_id = d.id 
                $where_clause 
                ORDER BY a.attendance_date DESC";

    $e_stmt = $conn->prepare($all_sql);
    $e_types = $types;
    $e_params = $params;
    // Remove the LIMIT/OFFSET params
    array_splice($e_params, -2, 2);
    $e_types = substr($e_types, 0, -2);
    if (!empty($e_params)) {
        $e_stmt->bind_param($e_types, ...$e_params);
    }
    $e_stmt->execute();
    $all_records = $e_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $e_stmt->close();

    if ($export_type === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="attendance_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Employee Code', 'Employee Name', 'Department', 'Check In', 'Check Out', 'Hours', 'Status', 'Late', 'Remarks']);
        foreach ($all_records as $r) {
            fputcsv($output, [
                $r['attendance_date'],
                $r['employee_code'],
                $r['employee_name'],
                $r['department_name'],
                $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '',
                $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '',
                $r['total_working_hours'] ? number_format($r['total_working_hours'], 2) : '',
                get_attendance_status_label($r['status']),
                $r['is_late'] ? 'Yes' : 'No',
                $r['remarks'] ?? ''
            ]);
        }
        fclose($output);
        exit;
    } elseif ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="attendance_' . date('Y-m-d') . '.xls"');
        echo "<table border='1'>";
        echo "<tr><th>Date</th><th>Employee Code</th><th>Employee Name</th><th>Department</th><th>Check In</th><th>Check Out</th><th>Hours</th><th>Status</th><th>Late</th><th>Remarks</th></tr>";
        foreach ($all_records as $r) {
            echo "<tr>";
            echo "<td>{$r['attendance_date']}</td>";
            echo "<td>{$r['employee_code']}</td>";
            echo "<td>{$r['employee_name']}</td>";
            echo "<td>{$r['department_name']}</td>";
            echo "<td>" . ($r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '') . "</td>";
            echo "<td>" . ($r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '') . "</td>";
            echo "<td>" . ($r['total_working_hours'] ? number_format($r['total_working_hours'], 2) : '') . "</td>";
            echo "<td>" . get_attendance_status_label($r['status']) . "</td>";
            echo "<td>" . ($r['is_late'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . htmlspecialchars($r['remarks'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance Management</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ 
    showAddModal: false, 
    showEditModal: false, 
    showDeleteModal: false,
    editId: 0,
    editCheckIn: '',
    editCheckOut: '',
    editStatus: '',
    editRemarks: '',
    deleteId: 0
}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php include "../includes/topbar.php"; ?>
        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter">

            <?php if ($message): ?>
                <div class="flex items-center gap-3 p-4 rounded-xl border text-sm <?php echo $message_type === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-xl shadow-lg shadow-emerald-500/25">
                        <i class="fa-solid fa-clipboard-list"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-extrabold text-white tracking-tight">Attendance Management</h1>
                        <p class="text-sm text-zinc-400">View, add, edit, and manage all attendance records</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="showAddModal = true" class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 text-white font-semibold text-sm rounded-xl shadow-lg shadow-emerald-500/25 transition-all hover:scale-105">
                        <i class="fa-solid fa-plus"></i> Add Manual
                    </button>
                    <div class="relative" x-data="{ exportOpen: false }">
                        <button @click="exportOpen = !exportOpen" class="inline-flex items-center gap-2 px-5 py-2.5 bg-sky-500/10 border border-sky-500/20 text-sky-400 font-semibold text-sm rounded-xl hover:bg-sky-500/20 transition-all">
                            <i class="fa-solid fa-download"></i> Export
                        </button>
                        <div x-show="exportOpen" @click.outside="exportOpen = false" class="absolute right-0 mt-2 w-40 bg-[#1E293B] rounded-xl shadow-xl border border-white/[0.06] z-50 overflow-hidden" style="display: none;">
                            <a href="?<?php echo $_SERVER['QUERY_STRING'] ?>&export=csv" class="block px-4 py-2.5 text-sm text-zinc-300 hover:bg-white/[0.04]">CSV</a>
                            <a href="?<?php echo $_SERVER['QUERY_STRING'] ?>&export=excel" class="block px-4 py-2.5 text-sm text-zinc-300 hover:bg-white/[0.04]">Excel</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" class="glass-strong rounded-2xl p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Department</label>
                        <select name="department" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                            <option value="0">All</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo $filter_dept === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Position</label>
                        <select name="position" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                            <option value="0">All</option>
                            <?php foreach ($positions as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $filter_position === (int)$p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['position_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Employee</label>
                        <select name="employee" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                            <option value="0">All</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?php echo $e['id']; ?>" <?php echo $filter_employee === (int)$e['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['name'] . ' (' . $e['employee_code'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Status</label>
                        <select name="att_status" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                            <option value="">All</option>
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $filter_status === $s ? 'selected' : ''; ?>><?php echo get_attendance_status_label($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Date From</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-zinc-400 mb-1">Date To</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                    </div>
                </div>
                <div class="flex items-center gap-3 mt-4">
                    <div class="flex-1">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or employee code..." class="w-full max-w-md bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                    </div>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white font-semibold text-sm rounded-xl transition-all">
                        <i class="fa-solid fa-filter mr-1"></i> Filter
                    </button>
                    <a href="attendance_management.php" class="px-4 py-2.5 bg-white/[0.06] hover:bg-white/[0.10] text-zinc-300 font-semibold text-sm rounded-xl transition-all">
                        <i class="fa-solid fa-rotate"></i> Reset
                    </a>
                </div>
            </form>

            <!-- Results Summary -->
            <div class="flex items-center justify-between text-sm">
                <p class="text-zinc-400">Showing <span class="font-semibold text-white"><?php echo count($records); ?></span> of <span class="font-semibold text-white"><?php echo $total_records; ?></span> records</p>
                <div class="flex items-center gap-2">
                    <span class="text-zinc-500 text-xs">Per page:</span>
                    <select name="per_page" onchange="window.location.href='?per_page='+this.value+'&<?php echo http_build_query(array_filter(['department' => $filter_dept, 'position' => $filter_position, 'employee' => $filter_employee, 'att_status' => $filter_status, 'date_from' => $date_from, 'date_to' => $date_to, 'search' => $search])); ?>'"
                        class="bg-white/[0.06] border border-white/10 text-white rounded-lg px-2 py-1.5 text-xs outline-none">
                        <?php foreach ([5, 10, 25, 50] as $pp): ?>
                            <option value="<?php echo $pp; ?>" <?php echo $per_page === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Data Table -->
            <div class="glass-strong rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider bg-white/[0.03]">
                            <tr>
                                <th class="px-5 py-4">Date</th>
                                <th class="px-4 py-4">Employee</th>
                                <th class="px-4 py-4">Department</th>
                                <th class="px-4 py-4 text-center">Check In</th>
                                <th class="px-4 py-4 text-center">Check Out</th>
                                <th class="px-4 py-4 text-center">Hours</th>
                                <th class="px-4 py-4 text-center">Status</th>
                                <th class="px-4 py-4 text-center">Late</th>
                                <th class="px-5 py-4 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="9" class="px-6 py-16 text-center text-zinc-500">No attendance records found.</td>
                                </tr>
                                <?php else: foreach ($records as $r): ?>
                                    <tr class="hover:bg-white/[0.02] transition-colors">
                                        <td class="px-5 py-3 font-medium text-white whitespace-nowrap"><?php echo date('M d, Y', strtotime($r['attendance_date'])); ?></td>
                                        <td class="px-4 py-3">
                                            <div class="font-semibold text-white text-xs"><?php echo htmlspecialchars($r['employee_name']); ?></div>
                                            <div class="text-[10px] text-zinc-500"><?php echo htmlspecialchars($r['employee_code']); ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-zinc-400 text-xs"><?php echo htmlspecialchars($r['department_name'] ?? 'N/A'); ?></td>
                                        <td class="px-4 py-3 text-center font-mono text-xs <?php echo $r['check_in'] ? ($r['is_late'] ? 'text-amber-400' : 'text-emerald-400') : 'text-zinc-600'; ?>">
                                            <?php echo $r['check_in'] ? date('h:i A', strtotime($r['check_in'])) : '—'; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center font-mono text-xs text-zinc-300">
                                            <?php echo $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '—'; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center text-xs text-zinc-300">
                                            <?php echo $r['total_working_hours'] ? number_format($r['total_working_hours'], 1) . 'h' : '—'; ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold <?php echo get_attendance_status_badge_class($r['status']); ?>">
                                                <?php echo get_attendance_status_label($r['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if ($r['is_late']): ?>
                                                <span class="text-amber-400 text-xs"><i class="fa-solid fa-clock"></i></span>
                                            <?php else: ?>
                                                <span class="text-zinc-600">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <button @click="editId=<?php echo $r['id']; ?>; editCheckIn='<?php echo $r['check_in'] ? date('H:i', strtotime($r['check_in'])) : ''; ?>'; editCheckOut='<?php echo $r['check_out'] ? date('H:i', strtotime($r['check_out'])) : ''; ?>'; editStatus='<?php echo $r['status']; ?>'; editRemarks='<?php echo htmlspecialchars(addslashes($r['remarks'] ?? ''), ENT_QUOTES); ?>'; showEditModal=true"
                                                    class="w-8 h-8 rounded-lg bg-sky-500/10 text-sky-400 hover:bg-sky-500/20 transition-all flex items-center justify-center text-xs" title="Edit">
                                                    <i class="fa-solid fa-pen"></i>
                                                </button>
                                                <button @click="deleteId=<?php echo $r['id']; ?>; showDeleteModal=true"
                                                    class="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-400 hover:bg-rose-500/20 transition-all flex items-center justify-center text-xs" title="Delete">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-5 py-4 border-t border-white/[0.06] flex items-center justify-between">
                        <p class="text-xs text-zinc-500">Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>
                        <div class="flex items-center gap-1">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&per_page=<?php echo $per_page; ?>&<?php echo http_build_query(array_filter(['department' => $filter_dept, 'position' => $filter_position, 'employee' => $filter_employee, 'att_status' => $filter_status, 'date_from' => $date_from, 'date_to' => $date_to, 'search' => $search])); ?>"
                                    class="px-3 py-1.5 rounded-lg text-xs bg-white/[0.06] hover:bg-white/[0.10] text-zinc-300 transition-all">Prev</a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&per_page=<?php echo $per_page; ?>&<?php echo http_build_query(array_filter(['department' => $filter_dept, 'position' => $filter_position, 'employee' => $filter_employee, 'att_status' => $filter_status, 'date_from' => $date_from, 'date_to' => $date_to, 'search' => $search])); ?>"
                                    class="px-3 py-1.5 rounded-lg text-xs <?php echo $i === $page ? 'bg-emerald-500/20 text-emerald-400 font-semibold' : 'bg-white/[0.06] hover:bg-white/[0.10] text-zinc-300'; ?> transition-all"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&per_page=<?php echo $per_page; ?>&<?php echo http_build_query(array_filter(['department' => $filter_dept, 'position' => $filter_position, 'employee' => $filter_employee, 'att_status' => $filter_status, 'date_from' => $date_from, 'date_to' => $date_to, 'search' => $search])); ?>"
                                    class="px-3 py-1.5 rounded-lg text-xs bg-white/[0.06] hover:bg-white/[0.10] text-zinc-300 transition-all">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Add Manual Modal -->
            <div x-show="showAddModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.outside="showAddModal = false">
                <div class="bg-[#1E293B] rounded-2xl border border-white/[0.06] shadow-2xl w-full max-w-xl max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-white/[0.06] flex items-center justify-between">
                        <h3 class="text-lg font-bold text-white"><i class="fa-solid fa-plus text-emerald-400 mr-2"></i>Add Manual Attendance</h3>
                        <button @click="showAddModal = false" class="w-8 h-8 rounded-lg bg-white/[0.06] text-zinc-400 hover:text-white flex items-center justify-center"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="add_manual">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Employee *</label>
                                <select name="employee_id" required class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                                    <option value="">Select Employee</option>
                                    <?php foreach ($active_employees as $e): ?>
                                        <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['name'] . ' (' . $e['employee_code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Date *</label>
                                <input type="date" name="attendance_date" required value="<?php echo date('Y-m-d'); ?>" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Check In</label>
                                <input type="time" name="check_in" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Check Out</label>
                                <input type="time" name="check_out" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Status *</label>
                                <select name="status" required class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?php echo $s; ?>"><?php echo get_attendance_status_label($s); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Remarks</label>
                                <input type="text" name="remarks" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-500/30">
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showAddModal = false" class="px-5 py-2.5 bg-white/[0.06] text-zinc-300 rounded-xl text-sm font-semibold hover:bg-white/[0.10] transition-all">Cancel</button>
                            <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-500 text-white rounded-xl text-sm font-semibold hover:scale-105 transition-all">Add Record</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Modal -->
            <div x-show="showEditModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.outside="showEditModal = false">
                <div class="bg-[#1E293B] rounded-2xl border border-white/[0.06] shadow-2xl w-full max-w-xl">
                    <div class="px-6 py-4 border-b border-white/[0.06] flex items-center justify-between">
                        <h3 class="text-lg font-bold text-white"><i class="fa-solid fa-pen text-sky-400 mr-2"></i>Edit Attendance</h3>
                        <button @click="showEditModal = false" class="w-8 h-8 rounded-lg bg-white/[0.06] text-zinc-400 hover:text-white flex items-center justify-center"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="edit_attendance">
                        <input type="hidden" name="att_id" x-model="editId">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Check In</label>
                                <input type="time" name="check_in" x-model="editCheckIn" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-sky-500/30">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Check Out</label>
                                <input type="time" name="check_out" x-model="editCheckOut" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-sky-500/30">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Status</label>
                                <select name="status" x-model="editStatus" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-sky-500/30">
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?php echo $s; ?>"><?php echo get_attendance_status_label($s); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-zinc-300 mb-1">Remarks</label>
                                <input type="text" name="remarks" x-model="editRemarks" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-sky-500/30">
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showEditModal = false" class="px-5 py-2.5 bg-white/[0.06] text-zinc-300 rounded-xl text-sm font-semibold hover:bg-white/[0.10] transition-all">Cancel</button>
                            <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-sky-500 to-blue-500 text-white rounded-xl text-sm font-semibold hover:scale-105 transition-all">Update Record</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.outside="showDeleteModal = false">
                <div class="bg-[#1E293B] rounded-2xl border border-white/[0.06] shadow-2xl w-full max-w-md">
                    <div class="p-6 text-center">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-rose-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-triangle-exclamation text-2xl text-rose-400"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2">Delete Attendance Record?</h3>
                        <p class="text-sm text-zinc-400">This action cannot be undone. The record will be permanently deleted.</p>
                    </div>
                    <form method="POST" class="px-6 pb-6 flex justify-center gap-3">
                        <input type="hidden" name="action" value="delete_attendance">
                        <input type="hidden" name="att_id" x-model="deleteId">
                        <button type="button" @click="showDeleteModal = false" class="px-5 py-2.5 bg-white/[0.06] text-zinc-300 rounded-xl text-sm font-semibold hover:bg-white/[0.10] transition-all">Cancel</button>
                        <button type="submit" class="px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-sm font-semibold transition-all">Delete</button>
                    </form>
                </div>
            </div>

        </main>
    </div>
</body>

</html>