<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

if (!payroll_table_exists($conn)) {
    $sql = file_get_contents(__DIR__ . '/../config/migration_new_payroll_table.sql');
    $conn->multi_query($sql);
    while ($conn->next_result()) { $conn->store_result(); }
}

$message = '';
$message_type = '';
$currency = get_currency($conn);

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token()) {
        $message = 'Invalid CSRF token.';
        $message_type = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_status') {
            $pid = (int)($_POST['payroll_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? '';
            $remarks = trim($_POST['remarks'] ?? '');
            if ($pid > 0 && in_array($new_status, ['Pending', 'Paid', 'Cancelled'])) {
                if (update_new_payroll_status($conn, $pid, $new_status, $remarks ?: null)) {
                    $message = "Payroll status updated to $new_status.";
                    $message_type = 'success';
                } else {
                    $message = 'Failed to update status.';
                    $message_type = 'error';
                }
            }
        } elseif ($action === 'recalc') {
            $pid = (int)($_POST['payroll_id'] ?? 0);
            $detail = get_new_payroll_detail($conn, $pid);
            if ($detail) {
                generate_new_payroll($conn, $detail['employee_id'], $detail['pay_month'], $detail['pay_year']);
                $message = "Payroll recalculated for " . htmlspecialchars($detail['name']) . ".";
                $message_type = 'success';
            }
        }
        header('Location: payroll_management.php' . (!empty($_POST['query_string']) ? '?' . $_POST['query_string'] : ''));
        exit;
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$filter_dept = (int)($_GET['department'] ?? 0);
$filter_status = $_GET['status'] ?? '';
$filter_month = (int)($_GET['month'] ?? 0);
$filter_year = (int)($_GET['year'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(100, max(5, (int)($_GET['per_page'] ?? 25)));

$valid_statuses = ['Pending', 'Paid', 'Cancelled'];

// Build query
$where = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where[] = "(e.name LIKE ? OR e.employee_code LIKE ?)";
    $sp = '%' . $search . '%';
    $params[] = $sp;
    $params[] = $sp;
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

// Count
$count_sql = "SELECT COUNT(*) as total FROM payroll p JOIN employee e ON p.employee_id = e.id $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_records = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = max(1, ceil($total_records / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Fetch
$sql = "SELECT p.*, e.name, e.employee_code, d.department_name, pos.position_name
        FROM payroll p
        JOIN employee e ON p.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions pos ON e.position_id = pos.id
        $where_sql
        ORDER BY p.pay_year DESC, p.pay_month DESC, e.name ASC
        LIMIT ? OFFSET ?";
$f_params = $params;
$f_types = $types;
$f_types .= 'ii';
$f_params[] = $per_page;
$f_params[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($f_types, ...$f_params);
$stmt->execute();
$payrolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary stats for current filter
$sum_sql = "SELECT 
    COUNT(*) as emp_count,
    COALESCE(SUM(p.net_salary), 0) as total_net,
    COALESCE(SUM(p.overtime_amount), 0) as total_ot,
    COALESCE(SUM(p.bonus), 0) as total_bonus,
    COALESCE(SUM(p.leave_deduction + p.half_day_deduction + p.late_deduction + p.absent_deduction + p.other_deduction), 0) as total_deductions
    FROM payroll p JOIN employee e ON p.employee_id = e.id $where_sql";
$sum_stmt = $conn->prepare($sum_sql);
if (!empty($params)) $sum_stmt->bind_param($types, ...$params);
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();
$sum_stmt->close();

$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

$qs_params = [];
if (!empty($search)) $qs_params['search'] = $search;
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
    <title>HNIN AKARI NWE · Payroll Management</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .payroll-stat{position:relative;overflow:hidden;background:var(--glass-strong-bg);backdrop-filter:blur(20px);border:1px solid var(--glass-strong-border);border-radius:1rem;padding:1.25rem;transition:all .35s cubic-bezier(.16,1,.3,1)}
        .payroll-stat:hover{transform:translateY(-4px);box-shadow:var(--shadow-card-hover)}
        .payroll-stat::after{content:'';position:absolute;top:-50%;right:-50%;width:100%;height:100%;border-radius:50%;opacity:.06;transition:opacity .3s}
        .payroll-stat:hover::after{opacity:.12}
        .payroll-stat.net::after{background:#10B981}
        .payroll-stat.ot::after{background:#F59E0B}
        .payroll-stat.bonus::after{background:#6366F1}
        .payroll-stat.ded::after{background:#F43F5E}
        .stat-icon-box{width:2.75rem;height:2.75rem;border-radius:.875rem;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
        .sheet-card{position:relative;overflow:hidden;background:var(--glass-strong-bg);backdrop-filter:blur(20px);border:1px solid var(--glass-strong-border);border-radius:1.25rem;transition:all .35s cubic-bezier(.16,1,.3,1)}
        .sheet-card:hover{box-shadow:var(--shadow-card-hover)}
        .sheet-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#1E3A8A,#4F46E5,#F59E0B);opacity:0;transition:opacity .3s ease}
        .sheet-card:hover::before{opacity:1}
        .table-row{transition:all .2s ease}
        .table-row:hover{background:linear-gradient(90deg,rgba(139,92,246,.03),rgba(217,70,239,.02),transparent)!important}
        .status-badge{display:inline-flex;align-items:center;gap:.375rem;padding:.35rem .75rem;border-radius:9999px;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
        .employee-cell{display:flex;align-items:center;gap:.75rem}
        .employee-avatar{width:2.5rem;height:2.5rem;border-radius:.75rem;background:linear-gradient(135deg,#1E3A8A,#4F46E5);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0;box-shadow:0 4px 12px rgba(139,92,246,.2)}
        .amount-positive{display:inline-flex;align-items:center;gap:.25rem;color:#F59E0B}
        .amount-positive::before{content:'+';font-size:.625rem;font-weight:700}
        .amount-deduction{display:inline-flex;align-items:center;gap:.25rem;color:#F43F5E}
        .amount-deduction::before{content:'\2212';font-size:.75rem;font-weight:700}
        .net-highlight{display:inline-flex;align-items:center;gap:.375rem;padding:.375rem .75rem;border-radius:.625rem;background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.2);color:#A78BFA;font-weight:700;font-family:'Courier New',monospace}
        .filter-input{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff;font-size:.875rem;border-radius:.75rem;padding:.625rem .875rem;outline:none;transition:all .2s}
        .filter-input:focus{ring:2;box-shadow:0 0 0 2px rgba(99,102,241,.3)}
        .filter-input::placeholder{color:#71717a}
        .action-btn{display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;border-radius:.5rem;transition:all .2s}
        .pagination-link{display:inline-flex;align-items:center;justify-content:center;min-width:2rem;height:2rem;border-radius:.5rem;font-size:.75rem;font-weight:600;transition:all .2s}
        .pagination-link.active{background:linear-gradient(135deg,#4F46E5,#6366F1);color:#fff;box-shadow:0 4px 12px rgba(99,102,241,.3)}
        .pagination-link:not(.active){background:rgba(255,255,255,.06);color:#a1a1aa;border:1px solid rgba(255,255,255,.08)}
        .pagination-link:not(.active):hover{background:rgba(255,255,255,.1);color:#fff}
    </style>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Payroll Management";
            $page_subtitle = "View, search, and manage all payroll records across employees.";
            ob_start();
        ?>
        <div class="flex items-center gap-3">
            <a href="payroll.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-500/15 text-emerald-400 hover:bg-emerald-500/25 text-sm font-semibold transition-colors">
                <i class="fa-solid fa-bolt text-xs"></i> Generate Payroll
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
                        <p class="font-semibold <?php echo $message_type == 'success' ? 'text-emerald-400' : 'text-red-400'; ?>"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Summary Stats -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
                <div class="payroll-stat net animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-emerald-500/20 to-teal-500/10"><i class="fa-solid fa-dollar-sign text-emerald-500"></i></div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Net Payout</span>
                            <p class="text-xl font-extrabold text-emerald-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($summary['total_net'], 2); ?></p>
                            <p class="text-[10px] text-zinc-500 mt-0.5"><?php echo $summary['emp_count']; ?> records</p>
                        </div>
                    </div>
                </div>
                <div class="payroll-stat ot animate-fade-in-up stagger-2">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-amber-500/20 to-orange-500/10"><i class="fa-solid fa-clock text-amber-500"></i></div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Overtime Total</span>
                            <p class="text-xl font-extrabold text-amber-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($summary['total_ot'], 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="payroll-stat bonus animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-indigo-500/20 to-blue-500/10"><i class="fa-solid fa-gift text-indigo-500"></i></div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Bonuses</span>
                            <p class="text-xl font-extrabold text-indigo-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($summary['total_bonus'], 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="payroll-stat ded animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-rose-500/20 to-pink-500/10"><i class="fa-solid fa-chart-line text-rose-500"></i></div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Deductions</span>
                            <p class="text-xl font-extrabold text-rose-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($summary['total_deductions'], 2); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Filters -->
            <form method="GET" class="mb-6 glass-strong rounded-xl p-4 flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 rounded-lg bg-blue-500/15 flex items-center justify-center"><i class="fa-solid fa-magnifying-glass text-blue-500 text-sm"></i></div>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name or code..." class="filter-input w-48">
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 rounded-lg bg-purple-500/15 flex items-center justify-center"><i class="fa-solid fa-building text-purple-500 text-sm"></i></div>
                    <select name="department" class="filter-input min-w-[140px]">
                        <option value="0">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $filter_dept == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 rounded-lg bg-amber-500/15 flex items-center justify-center"><i class="fa-regular fa-calendar text-amber-500 text-sm"></i></div>
                    <select name="month" class="filter-input min-w-[120px]">
                        <option value="0">All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 rounded-lg bg-indigo-500/15 flex items-center justify-center"><i class="fa-solid fa-clock-rotate-left text-indigo-500 text-sm"></i></div>
                    <select name="year" class="filter-input min-w-[90px]">
                        <option value="0">All Years</option>
                        <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 rounded-lg bg-emerald-500/15 flex items-center justify-center"><i class="fa-solid fa-circle-check text-emerald-500 text-sm"></i></div>
                    <select name="status" class="filter-input min-w-[120px]">
                        <option value="">All Status</option>
                        <?php foreach ($valid_statuses as $vs): ?>
                        <option value="<?php echo $vs; ?>" <?php echo $filter_status === $vs ? 'selected' : ''; ?>><?php echo $vs; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold text-sm hover:shadow-lg hover:shadow-blue-500/25 transition-all duration-200">
                    <i class="fa-solid fa-filter text-xs"></i> Filter
                </button>
                <?php if (!empty($search) || $filter_dept > 0 || !empty($filter_status) || $filter_month > 0 || $filter_year > 0): ?>
                <a href="payroll_management.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-zinc-400 hover:text-white text-sm font-medium transition-all">
                    <i class="fa-solid fa-xmark text-xs"></i> Clear
                </a>
                <?php endif; ?>
            </form>

            <!-- Payroll Table -->
            <section class="sheet-card animate-fade-in-up stagger-5">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-file-invoice-dollar text-blue-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Payroll Records</h2>
                            <p class="text-xs text-zinc-500 mt-0.5"><?php echo $total_records; ?> total records</p>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-3 py-4 text-center">Month</th>
                                <th class="px-3 py-4 text-center" title="Present"><i class="fa-solid fa-calendar-check text-emerald-400"></i></th>
                                <th class="px-3 py-4 text-center" title="Half Days"><i class="fa-solid fa-circle-half-stroke text-teal-400"></i></th>
                                <th class="px-3 py-4 text-center" title="Leave"><i class="fa-solid fa-plane-departure text-blue-400"></i></th>
                                <th class="px-3 py-4 text-center" title="Late"><i class="fa-solid fa-hourglass-half text-amber-400"></i></th>
                                <th class="px-3 py-4 text-center" title="Absent"><i class="fa-solid fa-calendar-xmark text-red-400"></i></th>
                                <th class="px-3 py-4 text-center" title="OT Hours"><i class="fa-solid fa-stopwatch text-purple-400"></i></th>
                                <th class="px-4 py-4 text-right">Basic</th>
                                <th class="px-4 py-4 text-right">OT</th>
                                <th class="px-4 py-4 text-right">Bonus</th>
                                <th class="px-4 py-4 text-right">Deductions</th>
                                <th class="px-5 py-4 text-right font-bold">Net</th>
                                <th class="px-4 py-4 text-center">Status</th>
                                <th class="px-4 py-4 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php if (empty($payrolls)): ?>
                            <tr>
                                <td colspan="15" class="px-6 py-16 text-center">
                                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500/10 to-indigo-500/10 flex items-center justify-center mx-auto mb-4">
                                        <i class="fa-solid fa-file-invoice-dollar text-2xl text-zinc-500"></i>
                                    </div>
                                    <p class="text-zinc-400 font-medium">No payroll records found</p>
                                    <p class="text-zinc-500 text-sm mt-2">Try adjusting your filters or <a href="payroll.php" class="text-emerald-400 font-semibold hover:underline">generate payroll</a>.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($payrolls as $idx => $p): ?>
                                <?php $month_label = date('M Y', mktime(0,0,0,$p['pay_month'],1,$p['pay_year'])); ?>
                                <tr class="table-row animate-fade-in-up" style="animation-delay:<?php echo 0.05 + ($idx * 0.03); ?>s;">
                                    <td class="px-6 py-4">
                                        <div class="employee-cell">
                                            <div class="employee-avatar"><?php echo strtoupper(substr($p['name'], 0, 2)); ?></div>
                                            <div>
                                                <div class="font-semibold text-white"><?php echo htmlspecialchars($p['name']); ?></div>
                                                <div class="text-[11px] text-zinc-500 font-medium"><?php echo htmlspecialchars($p['employee_code']); ?> &middot; <?php echo htmlspecialchars($p['department_name'] ?? '-'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-zinc-300"><?php echo $month_label; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-emerald-400"><?php echo $p['present_days']; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-teal-400"><?php echo $p['half_days'] ?? 0; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-blue-400"><?php echo $p['leave_days']; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-amber-400"><?php echo $p['late_days']; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-red-400"><?php echo $p['absent_days']; ?></span></td>
                                    <td class="px-3 py-4 text-center"><span class="text-xs font-bold text-purple-400"><?php echo number_format($p['overtime_hours'], 1); ?>h</span></td>
                                    <td class="px-4 py-4 text-right font-mono text-white font-medium"><?php echo $currency; ?> <?php echo number_format($p['basic_salary'], 2); ?></td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['overtime_amount'] > 0): ?>
                                            <span class="amount-positive font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($p['overtime_amount'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <?php if ($p['bonus'] > 0): ?>
                                            <span class="inline-flex items-center gap-1 font-mono text-emerald-400 font-medium"><i class="fa-solid fa-plus text-[9px]"></i><?php echo $currency; ?> <?php echo number_format($p['bonus'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <?php
                                        $total_ded = $p['leave_deduction'] + ($p['half_day_deduction'] ?? 0) + $p['late_deduction'] + $p['absent_deduction'] + $p['other_deduction'];
                                        if ($total_ded > 0): ?>
                                            <span class="amount-deduction font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($total_ded, 2); ?></span>
                                        <?php else: ?>
                                            <span class="font-mono text-zinc-600">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <span class="net-highlight"><?php echo $currency; ?> <?php echo number_format($p['net_salary'], 2); ?></span>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <span class="status-badge <?php echo get_new_payroll_status_badge($p['payment_status']); ?>">
                                            <i class="fa-solid <?php echo get_new_payroll_status_icon($p['payment_status']); ?> text-[9px]"></i>
                                            <?php echo $p['payment_status']; ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <a href="payroll_detail.php?id=<?php echo $p['id']; ?>" class="action-btn bg-indigo-500/15 text-indigo-400 hover:bg-indigo-500/25" title="View Details"><i class="fa-solid fa-eye text-[10px]"></i></a>
                                            <form method="POST" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="payroll_id" value="<?php echo $p['id']; ?>">
                                                <input type="hidden" name="query_string" value="<?php echo htmlspecialchars($query_string); ?>">
                                                <button type="submit" name="action" value="recalc" class="action-btn bg-blue-500/15 text-blue-400 hover:bg-blue-500/25" title="Recalculate"><i class="fa-solid fa-rotate text-[10px]"></i></button>
                                            </form>
                                            <?php if ($p['payment_status'] === 'Pending'): ?>
                                            <form method="POST" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="payroll_id" value="<?php echo $p['id']; ?>">
                                                <input type="hidden" name="new_status" value="Paid">
                                                <input type="hidden" name="query_string" value="<?php echo htmlspecialchars($query_string); ?>">
                                                <button type="submit" name="action" value="update_status" class="action-btn bg-emerald-500/15 text-emerald-400 hover:bg-emerald-500/25" title="Mark Paid"><i class="fa-solid fa-circle-check text-[10px]"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-white/[0.06] flex items-center justify-between">
                    <span class="text-xs text-zinc-500">Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?></span>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $page - 1])); ?>" class="pagination-link"><i class="fa-solid fa-chevron-left text-[10px]"></i></a>
                        <?php endif; ?>
                        <?php
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);
                        for ($i = $start_p; $i <= $end_p; $i++):
                        ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $i])); ?>" class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($qs_params, ['page' => $page + 1])); ?>" class="pagination-link"><i class="fa-solid fa-chevron-right text-[10px]"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
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
