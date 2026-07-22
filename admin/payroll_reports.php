<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$department_id = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;
$employee_id = !empty($_GET['employee_id']) ? (int)$_GET['employee_id'] : null;
$status = !empty($_GET['status']) ? $_GET['status'] : null;
$report_tab = $_GET['report_tab'] ?? 'monthly';

$report_data = [];
$generated = isset($_GET['generate']);

if ($generated) {
    $report_data = get_payroll_report_data($conn, $start_date, $end_date, $department_id, $employee_id, $status);
}

// Export handler
if (isset($_GET['export']) && $generated) {
    $export_headers = ['Employee', 'Code', 'Department', 'Period', 'Basic', 'OT', 'Bonus', 'Deduction', 'Gross', 'Net', 'Status'];
    $export_data = [];
    foreach ($report_data as $row) {
        $period = date('M Y', mktime(0, 0, 0, $row['pay_month'], 1, $row['pay_year']));
        $export_data[] = [
            'employee' => $row['name'] ?? '',
            'code' => $row['employee_code'] ?? '',
            'department' => $row['department_name'] ?? '',
            'period' => $period,
            'basic' => $row['basic_salary'] ?? 0,
            'ot' => $row['overtime_amount'] ?? 0,
            'bonus' => $row['bonus'] ?? 0,
            'deduction' => $row['total_deduction'] ?? 0,
            'gross' => $row['gross_salary'] ?? 0,
            'net' => $row['net_salary'] ?? 0,
            'status' => $row['status'] ?? '',
        ];
    }
    $filename = 'payroll_report_' . $start_date . '_to_' . $end_date;
    if ($_GET['export'] === 'csv') {
        export_to_csv($export_data, $export_headers, $filename . '.csv');
    } elseif ($_GET['export'] === 'excel') {
        export_to_excel($export_data, $export_headers, $filename);
    }
}

// Summary stats
$total_records = count($report_data);
$total_net = 0;
$total_ot = 0;
$total_bonus = 0;
$total_ded = 0;
foreach ($report_data as $row) {
    $total_net += $row['net_salary'] ?? 0;
    $total_ot += $row['overtime_amount'] ?? 0;
    $total_bonus += $row['bonus'] ?? 0;
    $total_ded += $row['total_deduction'] ?? 0;
}
$avg_net = $total_records > 0 ? $total_net / $total_records : 0;

// Chart data: group by month
$monthly_chart = [];
foreach ($report_data as $row) {
    $key = $row['pay_year'] . '-' . str_pad($row['pay_month'], 2, '0', STR_PAD_LEFT);
    if (!isset($monthly_chart[$key])) {
        $monthly_chart[$key] = ['label' => date('M Y', mktime(0, 0, 0, $row['pay_month'], 1, $row['pay_year'])), 'net' => 0, 'ot' => 0, 'bonus' => 0, 'ded' => 0];
    }
    $monthly_chart[$key]['net'] += $row['net_salary'] ?? 0;
    $monthly_chart[$key]['ot'] += $row['overtime_amount'] ?? 0;
    $monthly_chart[$key]['bonus'] += $row['bonus'] ?? 0;
    $monthly_chart[$key]['ded'] += $row['total_deduction'] ?? 0;
}
ksort($monthly_chart);

// Fetch departments and employees for filters
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name ASC")->fetch_all(MYSQLI_ASSOC);
$employees = $conn->query("SELECT id, name, employee_code FROM employee WHERE status = 'active' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Payroll Reports</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .report-stat {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .report-stat:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
        }
        .report-stat::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            opacity: 0.06;
            transition: opacity 0.3s;
        }
        .report-stat:hover::after { opacity: 0.12; }
        .report-stat.records::after { background: #3B82F6; }
        .report-stat.net::after { background: #10B981; }
        .report-stat.avg::after { background: #8B5CF6; }
        .report-stat.ot::after { background: #F59E0B; }
        .report-stat.bonus::after { background: #6366F1; }
        .report-stat.ded::after { background: #F43F5E; }
        .stat-icon-box {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .sheet-card {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sheet-card:hover {
            box-shadow: var(--shadow-card-hover);
        }
        .sheet-card::before {
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
        .sheet-card:hover::before { opacity: 1; }
        .table-row {
            transition: all 0.2s ease;
        }
        .table-row:hover {
            background: linear-gradient(90deg, rgba(139,92,246,0.03), rgba(217,70,239,0.02), transparent) !important;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .employee-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .employee-avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #1E3A8A, #4F46E5);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(139,92,246,0.2);
        }
        .amount-positive {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            color: #F59E0B;
        }
        .amount-positive::before {
            content: '+';
            font-size: 0.625rem;
            font-weight: 700;
        }
        .amount-deduction {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            color: #F43F5E;
        }
        .amount-deduction::before {
            content: '−';
            font-size: 0.75rem;
            font-weight: 700;
        }
        .net-highlight {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.625rem;
            background: rgba(139,92,246,0.1);
            border: 1px solid rgba(139,92,246,0.2);
            color: #A78BFA;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
        }
        .tab-btn.active {
            background: linear-gradient(135deg, #4F46E5, #6366F1);
            color: white;
            box-shadow: 0 4px 15px rgba(99,102,241,0.3);
        }
        .tab-btn:not(.active) {
            background: rgba(255,255,255,0.04);
            color: #94A3B8;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .tab-btn:not(.active):hover {
            background: rgba(255,255,255,0.08);
            color: #E2E8F0;
        }
        .generate-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.5rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 15px rgba(16,185,129,0.25);
        }
        .generate-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16,185,129,0.35);
        }
        .generate-btn:active { transform: translateY(0); }
        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .export-btn.csv {
            background: rgba(16,185,129,0.1);
            color: #10B981;
            border: 1px solid rgba(16,185,129,0.2);
        }
        .export-btn.csv:hover {
            background: rgba(16,185,129,0.2);
            transform: translateY(-1px);
        }
        .export-btn.excel {
            background: rgba(59,130,246,0.1);
            color: #3B82F6;
            border: 1px solid rgba(59,130,246,0.2);
        }
        .export-btn.excel:hover {
            background: rgba(59,130,246,0.2);
            transform: translateY(-1px);
        }
        .export-btn.print {
            background: rgba(245,158,11,0.1);
            color: #F59E0B;
            border: 1px solid rgba(245,158,11,0.2);
        }
        .export-btn.print:hover {
            background: rgba(245,158,11,0.2);
            transform: translateY(-1px);
        }
        @media print {
            .no-print { display: none !important; }
            .main-wrapper { margin-left: 0 !important; }
            .emp-sidebar { display: none !important; }
            header { display: none !important; }
            .print-only { display: block !important; }
        }
        .print-only { display: none; }
    </style>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Payroll Reports";
            $page_subtitle = "Comprehensive payroll analytics with filtering, export, and chart visualization.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <input type="hidden" name="report_tab" :value="reportTab">
            <input type="hidden" name="generate" value="1">

            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-blue-500/15 flex items-center justify-center">
                    <i class="fa-regular fa-calendar text-blue-500 text-sm"></i>
                </div>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                    class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30">
            </div>

            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-indigo-500/15 flex items-center justify-center">
                    <i class="fa-regular fa-calendar-check text-indigo-500 text-sm"></i>
                </div>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                    class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-blue-500/30">
            </div>

            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-lg bg-purple-500/15 flex items-center justify-center">
                    <i class="fa-solid fa-filter text-purple-500 text-sm"></i>
                </div>
                <select name="status" class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-purple-500/30 min-w-[130px]">
                    <option value="">All Status</option>
                    <?php foreach (['Draft','Generated','Reviewed','Approved','Paid','Cancelled'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="generate-btn" onclick="document.querySelector('[name=report_tab]').value = reportTab">
                <i class="fa-solid fa-chart-column"></i> Generate Report
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto" x-data="{ reportTab: '<?php echo htmlspecialchars($report_tab); ?>' }">

            <!-- Report Type Tabs -->
            <div class="flex flex-wrap items-center gap-2 mb-6 no-print">
                <button type="button" class="tab-btn" :class="{ 'active': reportTab === 'monthly' }" @click="reportTab = 'monthly'">
                    <i class="fa-regular fa-calendar"></i> Monthly
                </button>
                <button type="button" class="tab-btn" :class="{ 'active': reportTab === 'yearly' }" @click="reportTab = 'yearly'">
                    <i class="fa-solid fa-calendar-days"></i> Yearly
                </button>
                <button type="button" class="tab-btn" :class="{ 'active': reportTab === 'department' }" @click="reportTab = 'department'">
                    <i class="fa-solid fa-building"></i> Department
                </button>
                <button type="button" class="tab-btn" :class="{ 'active': reportTab === 'employee' }" @click="reportTab = 'employee'">
                    <i class="fa-solid fa-user"></i> Employee
                </button>
                <button type="button" class="tab-btn" :class="{ 'active': reportTab === 'bonus' }" @click="reportTab = 'bonus'">
                    <i class="fa-solid fa-gift"></i> Bonus
                </button>
                <button type="button" class="tab-btn" :class="{ 'active': reportTab === 'deduction' }" @click="reportTab = 'deduction'">
                    <i class="fa-solid fa-minus-circle"></i> Deduction
                </button>
                <button type="button" class="tab-btn" :class="{ 'active': reportTab === 'overtime' }" @click="reportTab = 'overtime'">
                    <i class="fa-solid fa-clock"></i> Overtime
                </button>
            </div>

            <!-- Inline filters for Department tab -->
            <div x-show="reportTab === 'department'" x-transition class="mb-6 no-print">
                <div class="glass-strong rounded-xl p-4 flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-lg bg-emerald-500/15 flex items-center justify-center">
                            <i class="fa-solid fa-building text-emerald-500 text-sm"></i>
                        </div>
                        <select name="department_id" form="reportFilterForm"
                            class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-emerald-500/30 min-w-[200px]">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $department_id == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Inline filters for Employee tab -->
            <div x-show="reportTab === 'employee'" x-transition class="mb-6 no-print">
                <div class="glass-strong rounded-xl p-4 flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-lg bg-sky-500/15 flex items-center justify-center">
                            <i class="fa-solid fa-user text-sky-500 text-sm"></i>
                        </div>
                        <select name="employee_id" form="reportFilterForm"
                            class="bg-white/[0.06] border border-white/10 text-white text-sm rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-sky-500/30 min-w-[250px]">
                            <option value="">All Employees</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo $employee_id == $e['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['name'] . ' (' . $e['employee_code'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <?php if ($generated && !empty($report_data)): ?>

            <!-- Summary Stats -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-5 mb-8">
                <div class="report-stat records animate-fade-in-up stagger-1">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-blue-500/20 to-indigo-500/10">
                            <i class="fa-solid fa-database text-blue-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Records</span>
                            <p class="text-xl font-extrabold text-blue-400 mt-0.5"><?php echo number_format($total_records); ?></p>
                        </div>
                    </div>
                </div>

                <div class="report-stat net animate-fade-in-up stagger-2">
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

                <div class="report-stat avg animate-fade-in-up stagger-3">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-violet-500/20 to-purple-500/10">
                            <i class="fa-solid fa-calculator text-violet-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Avg Net</span>
                            <p class="text-xl font-extrabold text-violet-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($avg_net, 2); ?></p>
                        </div>
                    </div>
                </div>

                <div class="report-stat ot animate-fade-in-up stagger-4">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-amber-500/20 to-orange-500/10">
                            <i class="fa-solid fa-clock text-amber-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total OT</span>
                            <p class="text-xl font-extrabold text-amber-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_ot, 2); ?></p>
                        </div>
                    </div>
                </div>

                <div class="report-stat bonus animate-fade-in-up stagger-5">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-indigo-500/20 to-blue-500/10">
                            <i class="fa-solid fa-gift text-indigo-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Bonus</span>
                            <p class="text-xl font-extrabold text-indigo-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_bonus, 2); ?></p>
                        </div>
                    </div>
                </div>

                <div class="report-stat ded animate-fade-in-up stagger-6">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon-box bg-gradient-to-br from-rose-500/20 to-pink-500/10">
                            <i class="fa-solid fa-chart-line text-rose-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Total Ded</span>
                            <p class="text-xl font-extrabold text-rose-400 mt-0.5 truncate"><?php echo $currency; ?> <?php echo number_format($total_ded, 2); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Export & Chart Row -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6 no-print">
                <!-- Export Buttons -->
                <div class="flex items-center gap-2">
                    <?php
                    $export_params = http_build_query(array_filter([
                        'generate' => 1,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'department_id' => $department_id,
                        'employee_id' => $employee_id,
                        'status' => $status,
                        'report_tab' => $report_tab,
                    ]));
                    ?>
                    <a href="?<?php echo $export_params; ?>&export=csv" class="export-btn csv">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </a>
                    <a href="?<?php echo $export_params; ?>&export=excel" class="export-btn excel">
                        <i class="fa-solid fa-file-excel"></i> Export Excel
                    </a>
                    <button type="button" onclick="window.print()" class="export-btn print">
                        <i class="fa-solid fa-print"></i> Print
                    </button>
                </div>

                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20">
                    <i class="fa-solid fa-file-invoice-dollar text-indigo-400 text-xs"></i>
                    <span class="text-xs font-semibold text-indigo-400"><?php echo $total_records; ?> Records · <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?></span>
                </div>
            </div>

            <!-- Chart -->
            <?php if (count($monthly_chart) > 0): ?>
            <section class="sheet-card mb-8 animate-fade-in-up stagger-6 no-print">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-chart-column text-amber-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Monthly Breakdown</h2>
                            <p class="text-xs text-zinc-500 mt-0.5">Net salary, OT, bonus, and deduction by month</p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <canvas id="payrollReportChart" height="100"></canvas>
                </div>
            </section>
            <?php endif; ?>

            <!-- Results Table -->
            <section class="sheet-card animate-fade-in-up stagger-7">
                <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/10 flex items-center justify-center">
                            <i class="fa-solid fa-table-list text-blue-500"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-white text-lg">Payroll Report Results</h2>
                            <p class="text-xs text-zinc-500 mt-0.5"><?php echo $total_records; ?> records found</p>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">#</th>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-4 py-4">Code</th>
                                <th class="px-6 py-4">Department</th>
                                <th class="px-6 py-4">Period</th>
                                <th class="px-6 py-4 text-right">Basic</th>
                                <th class="px-6 py-4 text-right">OT</th>
                                <th class="px-6 py-4 text-right">Bonus</th>
                                <th class="px-6 py-4 text-right">Deduction</th>
                                <th class="px-6 py-4 text-right">Gross</th>
                                <th class="px-6 py-4 text-right font-bold">Net</th>
                                <th class="px-6 py-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            <?php foreach ($report_data as $idx => $row): ?>
                            <tr class="table-row animate-fade-in-up" style="animation-delay: <?php echo 0.05 + ($idx * 0.03); ?>s;">
                                <td class="px-6 py-4 text-zinc-500 text-xs font-medium"><?php echo $idx + 1; ?></td>
                                <td class="px-6 py-4">
                                    <div class="employee-cell">
                                        <div class="employee-avatar">
                                            <?php echo strtoupper(substr($row['name'] ?? 'XX', 0, 2)); ?>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-white"><?php echo htmlspecialchars($row['name'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="text-xs font-medium text-zinc-400 bg-white/[0.04] px-2 py-1 rounded-md"><?php echo htmlspecialchars($row['employee_code'] ?? ''); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-xs font-medium text-zinc-300"><?php echo htmlspecialchars($row['department_name'] ?? '—'); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-xs font-medium text-zinc-300"><?php echo date('M Y', mktime(0, 0, 0, $row['pay_month'], 1, $row['pay_year'])); ?></span>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-white font-medium"><?php echo $currency; ?> <?php echo number_format($row['basic_salary'] ?? 0, 2); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <?php if (($row['overtime_amount'] ?? 0) > 0): ?>
                                        <span class="amount-positive font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($row['overtime_amount'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="font-mono text-zinc-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <?php if (($row['bonus'] ?? 0) > 0): ?>
                                        <span class="inline-flex items-center gap-1 font-mono text-emerald-400 font-medium">
                                            <i class="fa-solid fa-plus text-[9px]"></i><?php echo $currency; ?> <?php echo number_format($row['bonus'], 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="font-mono text-zinc-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <?php if (($row['total_deduction'] ?? 0) > 0): ?>
                                        <span class="amount-deduction font-mono font-medium"><?php echo $currency; ?> <?php echo number_format($row['total_deduction'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="font-mono text-zinc-600">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-white font-medium"><?php echo $currency; ?> <?php echo number_format($row['gross_salary'] ?? 0, 2); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <span class="net-highlight"><?php echo $currency; ?> <?php echo number_format($row['net_salary'] ?? 0, 2); ?></span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="status-badge <?php echo get_payroll_status_badge($row['status'] ?? ''); ?>">
                                        <i class="fa-solid <?php echo get_payroll_status_icon($row['status'] ?? ''); ?> text-[10px]"></i>
                                        <?php echo htmlspecialchars($row['status'] ?? ''); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="border-t-2 border-white/10">
                            <tr class="font-bold">
                                <td colspan="5" class="px-6 py-4 text-zinc-400 text-xs uppercase tracking-wider">Totals (<?php echo $total_records; ?> records)</td>
                                <td class="px-6 py-4 text-right font-mono text-white"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($report_data, 'basic_salary')), 2); ?></td>
                                <td class="px-6 py-4 text-right font-mono text-amber-400"><?php echo $currency; ?> <?php echo number_format($total_ot, 2); ?></td>
                                <td class="px-6 py-4 text-right font-mono text-emerald-400"><?php echo $currency; ?> <?php echo number_format($total_bonus, 2); ?></td>
                                <td class="px-6 py-4 text-right font-mono text-rose-400"><?php echo $currency; ?> <?php echo number_format($total_ded, 2); ?></td>
                                <td class="px-6 py-4 text-right font-mono text-white"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($report_data, 'gross_salary')), 2); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <span class="net-highlight"><?php echo $currency; ?> <?php echo number_format($total_net, 2); ?></span>
                                </td>
                                <td class="px-6 py-4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <?php elseif ($generated): ?>

            <!-- Empty State -->
            <section class="sheet-card animate-fade-in-up stagger-6">
                <div class="p-16 text-center">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-500/10 to-indigo-500/10 flex items-center justify-center mx-auto mb-5">
                        <i class="fa-solid fa-file-invoice-dollar text-3xl text-zinc-500"></i>
                    </div>
                    <p class="text-zinc-400 font-medium text-lg">No payroll records found</p>
                    <p class="text-zinc-500 text-sm mt-2 max-w-md mx-auto">No results match your filter criteria for the selected date range. Try adjusting the date range, status, or department filters.</p>
                    <a href="payroll.php" class="inline-flex items-center gap-2 mt-6 px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-500 to-indigo-500 text-white text-sm font-semibold hover:shadow-lg hover:shadow-blue-500/25 transition-all">
                        <i class="fa-solid fa-calculator"></i> Go to Payroll Processing
                    </a>
                </div>
            </section>

            <?php else: ?>

            <!-- Default State -->
            <section class="sheet-card animate-fade-in-up stagger-6">
                <div class="p-16 text-center">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-emerald-500/10 to-teal-500/10 flex items-center justify-center mx-auto mb-5">
                        <i class="fa-solid fa-chart-column text-3xl text-zinc-500"></i>
                    </div>
                    <p class="text-zinc-400 font-medium text-lg">Generate a Payroll Report</p>
                    <p class="text-zinc-500 text-sm mt-2 max-w-md mx-auto">Select your date range, filters, and report type above, then click <strong class="text-emerald-400">"Generate Report"</strong> to view payroll analytics.</p>
                </div>
            </section>

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

    <?php if ($generated && !empty($report_data) && count($monthly_chart) > 0): ?>
    <script>
    (function() {
        var ctx = document.getElementById('payrollReportChart');
        if (!ctx) return;

        var labels = <?php echo json_encode(array_column($monthly_chart, 'label')); ?>;
        var netData = <?php echo json_encode(array_map(fn($v) => round($v['net'], 2), $monthly_chart)); ?>;
        var otData = <?php echo json_encode(array_map(fn($v) => round($v['ot'], 2), $monthly_chart)); ?>;
        var bonusData = <?php echo json_encode(array_map(fn($v) => round($v['bonus'], 2), $monthly_chart)); ?>;
        var dedData = <?php echo json_encode(array_map(fn($v) => round($v['ded'], 2), $monthly_chart)); ?>;
        var colors = getChartColors();

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Net Salary',
                        data: netData,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: 'rgba(16, 185, 129, 1)',
                        borderWidth: 1,
                        borderRadius: 6
                    },
                    {
                        label: 'Overtime',
                        data: otData,
                        backgroundColor: 'rgba(245, 158, 11, 0.7)',
                        borderColor: 'rgba(245, 158, 11, 1)',
                        borderWidth: 1,
                        borderRadius: 6
                    },
                    {
                        label: 'Bonus',
                        data: bonusData,
                        backgroundColor: 'rgba(99, 102, 241, 0.7)',
                        borderColor: 'rgba(99, 102, 241, 1)',
                        borderWidth: 1,
                        borderRadius: 6
                    },
                    {
                        label: 'Deduction',
                        data: dedData,
                        backgroundColor: 'rgba(244, 63, 94, 0.7)',
                        borderColor: 'rgba(244, 63, 94, 1)',
                        borderWidth: 1,
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, padding: 20, color: colors.text }
                    },
                    tooltip: {
                        backgroundColor: colors.tooltipBg,
                        borderColor: colors.tooltipBorder,
                        borderWidth: 1,
                        titleColor: colors.tooltipText,
                        bodyColor: colors.tooltipText,
                        padding: 12,
                        cornerRadius: 10,
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': $' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: colors.grid },
                        ticks: { color: colors.text }
                    },
                    y: {
                        grid: { color: colors.grid },
                        ticks: {
                            color: colors.text,
                            callback: function(val) { return '$' + val.toLocaleString(); }
                        }
                    }
                }
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
