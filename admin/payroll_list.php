<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$message = '';
$message_type = '';\n$currency = get_currency($conn);

// ─── POST Handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) { http_response_code(403); exit('CSRF validation failed.'); }

    $action = $_POST['action'] ?? '';
    $payroll_id = (int)($_POST['payroll_id'] ?? 0);

    if ($payroll_id > 0) {
        if ($action === 'pay') {
            $result = update_new_payroll_status($conn, $payroll_id, 'Paid');
            $message = $result ? 'Payroll marked as Paid successfully.' : 'Failed to update payroll status.';
            $message_type = $result ? 'success' : 'error';
        } elseif ($action === 'cancel') {
            $remarks = trim($_POST['remarks'] ?? '');
            if (empty($remarks)) {
                $message = 'Remarks are required for cancellation.';
                $message_type = 'error';
            } else {
                $result = update_new_payroll_status($conn, $payroll_id, 'Cancelled', $remarks);
                $message = $result ? 'Payroll cancelled successfully.' : 'Failed to cancel payroll.';
                $message_type = $result ? 'success' : 'error';
            }
        }
    }

    header('Location: payroll_list.php' . (!empty($_POST['query_string']) ? '?' . $_POST['query_string'] : ''));
    exit;
}

// ─── Filters ───────────────────────────────────────────────────
$search_name = trim($_GET['search'] ?? '');
$filter_dept = (int)($_GET['department'] ?? 0);
$filter_status = $_GET['status'] ?? '';
$filter_month = (int)($_GET['month'] ?? 0);
$filter_year = (int)($_GET['year'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, [5, 10, 25, 50, 100])) $per_page = 25;

$valid_statuses = ['Draft', 'Generated', 'Reviewed', 'Approved', 'Paid', 'Cancelled'];

// Build query conditions
$where = [];
$params = [];
$types = '';

if (!empty($search_name)) {
    $where[] = "(e.name LIKE ? OR e.employee_code LIKE ?)";
    $search_param = '%' . $search_name . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}
if ($filter_dept > 0) {
    $where[] = "e.department_id = ?";
    $params[] = $filter_dept;
    $types .= 'i';
}
if (!empty($filter_status) && in_array($filter_status, $valid_statuses)) {
    $where[] = "p.payment_status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
if ($filter_month > 0) {
    $where[] = "p.pay_month = ?";
    $params[] = $filter_month;
    $types .= 'i';
}
if ($filter_year > 0) {
    $where[] = "p.pay_year = ?";
    $params[] = $filter_year;
    $types .= 'i';
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$count_sql = "SELECT COUNT(*) as total FROM payroll p JOIN employee e ON p.employee_id = e.id $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = max(1, ceil($total_records / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Fetch payroll data
$sql = "SELECT p.*, e.name, e.employee_code, d.department_name, pos.position_name
        FROM payroll p
        JOIN employee e ON p.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions pos ON e.position_id = pos.id
        $where_sql
        ORDER BY p.pay_year DESC, p.pay_month DESC, e.name ASC
        LIMIT ? OFFSET ?";

$types .= 'ii';
$params[] = $per_page;
$params[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$payrolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary totals
$total_net = 0;
$total_deductions = 0;
foreach ($payrolls as $p) {
    $total_net += $p['net_salary'];
    $total_deductions += ($p['total_deduction'] ?? $p['other_deduction'] ?? 0);
}

// Departments for filter
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name ASC")->fetch_all(MYSQLI_ASSOC);

// Build query string for pagination/redirects
$qs_params = [];
if (!empty($search_name)) $qs_params['search'] = $search_name;
if ($filter_dept > 0) $qs_params['department'] = $filter_dept;
if (!empty($filter_status)) $qs_params['status'] = $filter_status;
if ($filter_month > 0) $qs_params['month'] = $filter_month;
if ($filter_year > 0) $qs_params['year'] = $filter_year;
$query_string = http_build_query($qs_params);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Payroll List</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .payroll-stat {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .payroll-stat:hover { transform: translateY(-4px); box-shadow: var(--shadow-card-hover); }
        .payroll-stat::after {
            content: '';
            position: absolute;
            top: -50%; right: -50%;
            width: 100%; height: 100%;
            border-radius: 50%;
            opacity: 0.06;
            transition: opacity 0.3s;
        }
        .payroll-stat:hover::after { opacity: 0.12; }
        .payroll-stat.count::after { background: #3B82F6; }
        .payroll-stat.net::after { background: #10B981; }
        .payroll-stat.ded::after { background: #F43F5E; }
        .stat-icon-box {
            width: 2.75rem; height: 2.75rem;
            border-radius: 0.875rem;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .sheet-card {
            position: relative; overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sheet-card:hover { box-shadow: var(--shadow-card-hover); }
        .sheet-card::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, #1E3A8A, #4F46E5, #F59E0B);
            opacity: 0; transition: opacity 0.3s ease;
        }
        .sheet-card:hover::before { opacity: 1; }
        .table-row { transition: all 0.2s ease; }
        .table-row:hover { background: linear-gradient(90deg, rgba(139,92,246,0.03), rgba(217,70,239,0.02), transparent) !important; }
        .status-badge {
            display: inline-flex; align-items: center; gap: 0.375rem;
            padding: 0.35rem 0.75rem; border-radius: 9999px;
            font-size: 0.7rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.05em;
        }
        .employee-cell { display: flex; align-items: center; gap: 0.75rem; }
        .employee-avatar {
            width: 2.5rem; height: 2.5rem; border-radius: 0.75rem;
            background: linear-gradient(135deg, #1E3A8A, #4F46E5);
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; font-weight: 700; flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(139,92,246,0.2);
        }
        .net-highlight {
            display: inline-flex; align-items: center; gap: 0.375rem;
            padding: 0.375rem 0.75rem; border-radius: 0.625rem;
            background: rgba(139,92,246,0.1); border: 1px solid rgba(139,92,246,0.2);
            color: #A78BFA; font-weight: 700; font-family: 'Courier New', monospace;
        }
        .action-btn {
            width: 2rem; height: 2rem; border-radius: 0.5rem;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 0.8rem; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .action-btn:hover { transform: translateY(-1px); }
        .action-view { background: rgba(99,102,241,0.12); color: #818CF8; border: 1px solid rgba(99,102,241,0.2); }
        .action-view:hover { background: rgba(99,102,241,0.2); box-shadow: 0 4px 12px rgba(99,102,241,0.15); }
        .action-pay { background: rgba(16,185,129,0.12); color: #34D399; border: 1px solid rgba(16,185,129,0.2); }
        .action-pay:hover { background: rgba(16,185,129,0.2); box-shadow: 0 4px 12px rgba(16,185,129,0.15); }
        .action-cancel { background: rgba(244,63,94,0.12); color: #FB7185; border: 1px solid rgba(244,63,94,0.2); }
        .action-cancel:hover { background: rgba(244,63,94,0.2); box-shadow: 0 4px 12px rgba(244,63,94,0.15); }
        .filter-input {
            background: white/[0.06]; border: 1px solid white/10;
            color: white; text-sm; border-radius: 0.75rem;
            padding: 0.625rem 0.875rem; outline: none; transition: all 0.2s ease;
        }
        .filter-btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.625rem 1.25rem; border-radius: 0.75rem;
            background: linear-gradient(135deg, #4F46E5, #1E3A8A);
            color: white; font-weight: 600; font-size: 0.875rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 15px rgba(79,70,229,0.25);
        }
        .filter-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(79,70,229,0.35); }
        .reset-btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.625rem 1.25rem; border-radius: 0.75rem;
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
            color: #94A3B8; font-weight: 600; font-size: 0.875rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reset-btn:hover { background: rgba(255,255,255,0.1); color: white; transform: translateY(-2px); }
        .pagination-btn {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 2.25rem; height: 2.25rem; border-radius: 0.625rem;
            font-size: 0.8rem; font-weight: 600; transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .pagination-btn.active { background: linear-gradient(135deg, #4F46E5, #1E3A8A); color: white; box-shadow: 0 4px 12px rgba(79,70,229,0.3); }
        .pagination-btn:not(.active) { background: rgba(255,255,255,0.04); color: #94A3B8; border-color: rgba(255,255,255,0.08); }
        .pagination-btn:not(.active):hover { background: rgba(255,255,255,0.08); color: white; }
    </style>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Payroll List";
            $page_subtitle = "View, filter, and manage all payroll records across employees and departments.";
            ob_start();
        ?>
        <?php echo csrf_field(); ?>
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

            <!-- Filter Bar -->
            <form method="GET" class="mb-6 glass-strong rounded-2xl p-5">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[200px]">
                        <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5 block">Employee Name</label>
                        <div class="relative">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500 text-xs"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Search by name or code..."
                                class="filter-input w-full pl-9">
                        </div>
                    </div>
                    <div class="min-w-[160px]">
                        <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5 block">Department</label>
                        <select name="department" class="filter-input w-full">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $filter_dept == $dept['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="min-w-[140px]">
                        <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5 block">Status</label>
                        <select name="status" class="filter-input w-full">
                            <option value="">All Status</option>
                            <?php foreach ($valid_statuses as $st): ?>
                                <option value="<?php echo $st; ?>" <?php echo $filter_status === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="min-w-[130px]">
                        <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5 block">Month</label>
                        <select name="month" class="filter-input w-full">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="min-w-[100px]">
                        <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5 block">Year</label>
                        <select name="year" class="filter-input w-full">
                            <option value="">All Years</option>
                            <?php for ($y = (int)date('Y') - 2; $y <= (int)date('Y') + 1; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="filter-btn">
                            <i class="fa-solid fa-filter"></i> Filter
                        </button>
                        <a href="payroll_list.php" class="reset-btn">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </a>
                    </div>
                </div>
            </form>

            <!-- Stats Cards -->
            <section class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
                <div class="payroll-stat count animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-blue-500/20 to-indigo-500/10">
                            <i class="fa-solid fa-file-invoice-dollar text-blue-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Records</span>
                            <p class="text-xl font-extrabold text-blue-400 mt-0.5"><?php echo number_format($total_records); ?></p>
                        </div>
                    </div>
                </div>
                <div class="payroll-stat net animate-fade-in-up stagger-2">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-emerald-500/20 to-teal-500/10">
                            <i class="fa-solid fa-dollar-sign text-emerald-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Net</span>
                            <p class="text-xl font-extrabold text-emerald-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_net, 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="payroll-stat ded animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-rose-500/20 to-pink-500/10">
                            <i class="fa-solid fa-chart-line text-rose-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Deductions</span>
                            <p class="text-xl font-extrabold text-rose-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_deductions, 2); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Payroll Table -->
            <section class="sheet-card animate-fade-in-up stagger-4">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-list-check text-blue-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Payroll Records</h2>
                            <p class="text-xs text-zinc-500 mt-0.5"><?php echo $total_records; ?> records found</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-xs text-zinc-500 font-medium">Show</label>
                        <select onchange="window.location.href='?<?php echo http_build_query(array_merge($qs_params, ['per_page' => 'REPLACE'])); ?>'.replace('REPLACE', this.value)"
                            class="bg-white/[0.06] border border-white/10 text-white text-xs rounded-lg px-2 py-1.5 outline-none focus:ring-2 focus:ring-indigo-500/30">
                            <?php foreach ([5, 10, 25, 50, 100] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $per_page == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="text-xs text-zinc-500 font-medium">per page</label>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-4 py-4">Department</th>
                                <th class="px-4 py-4">Period</th>
                                <th class="px-4 py-4 text-center">Present</th>
                                <th class="px-4 py-4 text-center">Leave</th>
                                <th class="px-4 py-4 text-center">OT Hours</th>
                                <th class="px-6 py-4 text-right">Gross</th>
                                <th class="px-6 py-4 text-right font-bold">Net</th>
                                <th class="px-4 py-4 text-center">Status</th>
                                <th class="px-6 py-4 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($payrolls)): ?>
                            <tr>
                                <td colspan="10" class="px-6 py-20 text-center">
                                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-500/10 to-indigo-500/10 flex items-center justify-center mx-auto mb-5">
                                        <i class="fa-solid fa-file-invoice-dollar text-3xl text-zinc-500"></i>
                                    </div>
                                    <p class="text-zinc-400 font-semibold text-lg">No payroll records found</p>
                                    <p class="text-zinc-500 text-sm mt-2 max-w-md mx-auto">Try adjusting your filters or run the payroll calculation from the <a href="payroll.php" class="text-indigo-400 hover:text-indigo-300 font-semibold underline underline-offset-2">Payroll Processing</a> page.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($payrolls as $idx => $p): ?>
                                <tr class="table-row animate-fade-in-up" style="animation-delay: <?php echo 0.05 + ($idx * 0.03); ?>s;">
                                    <td class="px-6 py-4">
                                        <div class="employee-cell">
                                            <div class="employee-avatar">
                                                <?php echo strtoupper(substr($p['name'], 0, 2)); ?>
                                            </div>
                                            <div>
                                                <div class="font-semibold text-white"><?php echo htmlspecialchars($p['name']); ?></div>
                                                <div class="text-[11px] text-zinc-500 font-medium"><?php echo htmlspecialchars($p['employee_code']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="text-xs font-medium text-zinc-300"><?php echo htmlspecialchars($p['department_name'] ?? '—'); ?></span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="text-xs font-medium text-zinc-300"><?php echo date('F', mktime(0, 0, 0, $p['pay_month'], 1)) . ' ' . $p['pay_year']; ?></span>
                                    </td>
                                    <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-emerald-400"><?php echo $p['present_days']; ?></span></td>
                                    <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-blue-400"><?php echo ($p['paid_leave_days'] ?? 0) + ($p['unpaid_leave_days'] ?? 0); ?></span></td>
                                    <td class="px-4 py-4 text-center"><span class="text-xs font-bold text-purple-400"><?php echo number_format($p['overtime_hours'] ?? 0, 1); ?>h</span></td>
                                    <td class="px-6 py-4 text-right font-mono text-white font-medium"><?php echo $currency; ?> <?php echo number_format($p['gross_salary'], 2); ?></td>
                                    <td class="px-6 py-4 text-right">
                                        <span class="net-highlight"><?php echo $currency; ?> <?php echo number_format($p['net_salary'], 2); ?></span>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <span class="status-badge <?php echo get_new_payroll_status_badge($p['payment_status']); ?>">
                                            <i class="fa-solid <?php echo get_new_payroll_status_icon($p['payment_status']); ?> text-[10px]"></i>
                                            <?php echo $p['payment_status']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <a href="payroll_detail.php?id=<?php echo $p['id']; ?>" class="action-btn action-view" title="View Details">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>

                                            <?php if ($p['payment_status'] === 'Generated' || $p['payment_status'] === 'Pending'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to mark this payroll as Paid?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="pay">
                                                <input type="hidden" name="payroll_id" value="<?php echo $p['id']; ?>">
                                                <input type="hidden" name="query_string" value="<?php echo htmlspecialchars($query_string); ?>">
                                                <button type="submit" class="action-btn action-pay" title="Mark as Paid">
                                                    <i class="fa-solid fa-sack-dollar"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if (!in_array($p['payment_status'], ['Paid', 'Cancelled'])): ?>
                                            <button type="button" class="action-btn action-cancel" title="Cancel"
                                                x-on:click="showCancelModal = true; cancelPayrollId = <?php echo $p['id']; ?>; cancelPayrollName = '<?php echo htmlspecialchars(addslashes($p['name'])); ?>'">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-white/[0.06] flex items-center justify-between flex-wrap gap-3">
                    <p class="text-xs text-zinc-500">
                        Showing <?php echo (($page - 1) * $per_page) + 1; ?>–<?php echo min($page * $per_page, $total_records); ?> of <?php echo $total_records; ?>
                    </p>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $page - 1, 'per_page' => $per_page])); ?>"
                            class="pagination-btn">
                            <i class="fa-solid fa-chevron-left text-[10px]"></i>
                        </a>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($start_page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => 1, 'per_page' => $per_page])); ?>" class="pagination-btn">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="text-zinc-600 text-xs px-1">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $i, 'per_page' => $per_page])); ?>"
                            class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="text-zinc-600 text-xs px-1">...</span>
                            <?php endif; ?>
                            <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $total_pages, 'per_page' => $per_page])); ?>" class="pagination-btn"><?php echo $total_pages; ?></a>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $page + 1, 'per_page' => $per_page])); ?>"
                            class="pagination-btn">
                            <i class="fa-solid fa-chevron-right text-[10px]"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </section>
        </main>

        <!-- Cancel Modal -->
        <div x-data="{ showCancelModal: false, cancelPayrollId: 0, cancelPayrollName: '' }"
            x-on:cancel-modal.window="showCancelModal = $event.detail?.show ?? false; cancelPayrollId = $event.detail?.id ?? 0; cancelPayrollName = $event.detail?.name ?? '';">

            <div x-show="showCancelModal" x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="display: none;">

                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" x-on:click="showCancelModal = false"></div>

                <div x-show="showCancelModal"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95 translate-y-4"
                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                    x-transition:leave-end="opacity-0 scale-95 translate-y-4"
                    class="relative w-full max-w-md bg-white dark:bg-[#1E293B] rounded-2xl shadow-2xl border border-slate-200 dark:border-white/[0.06] overflow-hidden" style="display: none;">

                    <div class="p-6 border-b border-slate-100 dark:border-white/[0.06]">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-rose-500/15 flex items-center justify-center">
                                <i class="fa-solid fa-ban text-rose-500"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-slate-900 dark:text-white">Cancel Payroll</h3>
                                <p class="text-xs text-slate-500 dark:text-zinc-500 mt-0.5">
                                    For: <span class="font-semibold text-slate-700 dark:text-zinc-300" x-text="cancelPayrollName"></span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <form method="POST" class="p-6">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="payroll_id" :value="cancelPayrollId">
                        <input type="hidden" name="query_string" value="<?php echo htmlspecialchars($query_string); ?>">

                        <div class="mb-5">
                            <label class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-2 block">Remarks (Required)</label>
                            <textarea name="remarks" rows="4" required placeholder="Enter the reason for cancellation..."
                                class="w-full bg-slate-50 dark:bg-white/[0.04] border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white text-sm rounded-xl p-3 outline-none focus:ring-2 focus:ring-rose-500/30 resize-none transition-all"></textarea>
                        </div>

                        <div class="flex items-center justify-end gap-3">
                            <button type="button" x-on:click="showCancelModal = false"
                                class="px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-all">
                                Close
                            </button>
                            <button type="submit"
                                class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-rose-500 to-red-500 text-white text-sm font-semibold shadow-lg shadow-rose-500/25 hover:shadow-rose-500/40 transition-all hover:-translate-y-0.5">
                                <i class="fa-solid fa-ban mr-1.5"></i> Cancel Payroll
                            </button>
                        </div>
                    </form>
                </div>
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
