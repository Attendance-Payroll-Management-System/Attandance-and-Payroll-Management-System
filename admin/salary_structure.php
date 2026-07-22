<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

set_mmt_timezone();

$message = '';
$message_type = '';

// POST handler - create structure
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_structure'])) {
    $employee_id = (int)$_POST['employee_id'];
    $basic_salary = (float)$_POST['basic_salary'];
    $effective_date = $_POST['effective_date'];

    if ($employee_id > 0 && $basic_salary > 0 && !empty($effective_date)) {
        $result = create_salary_structure($conn, $employee_id, $basic_salary, $effective_date, $_SESSION['admin_id']);
        if ($result) {
            $message = "Salary structure created successfully for the selected employee.";
            $message_type = "success";
        } else {
            $message = "Failed to create salary structure. Please try again.";
            $message_type = "error";
        }
    } else {
        $message = "Please fill in all required fields.";
        $message_type = "error";
    }
}

// GET handler - view history
$history_employee_id = isset($_GET['view_history']) ? (int)$_GET['view_history'] : 0;
$history_data = [];
if ($history_employee_id > 0) {
    $history_data = get_salary_structure_history($conn, $history_employee_id);
}

// Fetch active employees for dropdowns
$employees = [];
$emp_result = $conn->query("SELECT id, name, employee_code, basic_salary FROM employee WHERE status = 'active' ORDER BY name ASC");
if ($emp_result) {
    while ($row = $emp_result->fetch_assoc()) {
        $employees[] = $row;
    }
    $emp_result->close();
}

// Build employee JSON for Alpine.js
$employees_json = json_encode($employees);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Salary Structure</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .salary-stat {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .salary-stat:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
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
            background: linear-gradient(90deg, #10B981, #4F46E5, #0D9488);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sheet-card:hover::before { opacity: 1; }
        .table-row {
            transition: all 0.2s ease;
        }
        .table-row:hover {
            background: linear-gradient(90deg, rgba(16,185,129,0.03), rgba(79,70,229,0.02), transparent) !important;
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
        .status-active {
            background: rgba(16,185,129,0.12);
            color: #10B981;
            border: 1px solid rgba(16,185,129,0.2);
        }
        .status-inactive {
            background: rgba(244,63,94,0.12);
            color: #F43F5E;
            border: 1px solid rgba(244,63,94,0.2);
        }
        .submit-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.75rem;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #059669, #0D9488);
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 15px rgba(16,185,129,0.25);
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16,185,129,0.35);
        }
        .submit-btn:active { transform: translateY(0); }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
        }
        .tab-btn.active {
            background: linear-gradient(135deg, #059669, #0D9488);
            color: white;
            box-shadow: 0 4px 15px rgba(16,185,129,0.25);
        }
        .tab-btn:not(.active) {
            background: rgba(255,255,255,0.05);
            color: #a1a1aa;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .tab-btn:not(.active):hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #a1a1aa;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.06);
            color: white;
            font-size: 0.875rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .form-input:focus {
            border-color: rgba(16,185,129,0.5);
            box-shadow: 0 0 0 3px rgba(16,185,129,0.1);
        }
        .form-input option {
            background: #1e293b;
            color: white;
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
            background: linear-gradient(135deg, #059669, #0D9488);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(16,185,129,0.2);
        }
        .rate-card {
            position: relative;
            overflow: hidden;
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 1rem;
            padding: 1rem 1.25rem;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .rate-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-card-hover);
        }
    </style>
</head>
<body x-data="{
    tab: 'create',
    employees: <?php echo $employees_json; ?>,
    selectedEmployee: '',
    basicSalary: '',
    historyEmployee: '<?php echo $history_employee_id; ?>',
    updateSalary() {
        const emp = this.employees.find(e => e.id == this.selectedEmployee);
        if (emp) {
            this.basicSalary = emp.basic_salary;
        }
    }
}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Salary Structure";
            $page_subtitle = "Create and manage salary structures for employees with daily, hourly, and OT rate calculations.";
            ob_start();
        ?>
        <div class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <button type="button" @click="tab = 'create'" :class="tab === 'create' ? 'active' : ''" class="tab-btn">
                <i class="fa-solid fa-plus-circle"></i> Create Structure
            </button>
            <button type="button" @click="tab = 'history'" :class="tab === 'history' ? 'active' : ''" class="tab-btn">
                <i class="fa-solid fa-clock-rotate-left"></i> View History
            </button>
        </div>
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

            <!-- Tab 1: Create Structure -->
            <div x-show="tab === 'create'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">
                <div class="sheet-card animate-fade-in-up">
                    <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/10 flex items-center justify-center">
                                <i class="fa-solid fa-file-invoice-dollar text-emerald-500"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-white text-lg">Create Salary Structure</h2>
                                <p class="text-xs text-zinc-500 mt-0.5">Define basic salary and effective date for an employee</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <form method="POST" action="">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="create_structure" value="1">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Employee Select -->
                                <div>
                                    <label class="form-label">
                                        <i class="fa-solid fa-user text-emerald-400 mr-1"></i> Employee
                                    </label>
                                    <select name="employee_id" x-model="selectedEmployee" @change="updateSalary()" class="form-input" required>
                                        <option value="">-- Select Employee --</option>
                                        <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name'] . ' (' . $emp['employee_code'] . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Basic Salary -->
                                <div>
                                    <label class="form-label">
                                        <i class="fa-solid fa-dollar-sign text-emerald-400 mr-1"></i> Basic Salary
                                    </label>
                                    <input type="number" name="basic_salary" x-model="basicSalary" step="0.01" min="0" class="form-input" placeholder="0.00" required>
                                </div>

                                <!-- Effective Date -->
                                <div>
                                    <label class="form-label">
                                        <i class="fa-solid fa-calendar text-emerald-400 mr-1"></i> Effective Date
                                    </label>
                                    <input type="date" name="effective_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>

                            <div class="mt-8 flex justify-end">
                                <button type="submit" class="submit-btn">
                                    <i class="fa-solid fa-plus-circle"></i> Create Salary Structure
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tab 2: View History -->
            <div x-show="tab === 'history'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">
                <div class="sheet-card animate-fade-in-up">
                    <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500/20 to-blue-500/10 flex items-center justify-center">
                                <i class="fa-solid fa-clock-rotate-left text-indigo-500"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-white text-lg">Salary Structure History</h2>
                                <p class="text-xs text-zinc-500 mt-0.5">View salary structure changes over time</p>
                            </div>
                        </div>
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20">
                            <i class="fa-solid fa-users text-indigo-400 text-xs"></i>
                            <span class="text-xs font-semibold text-indigo-400"><?php echo count($employees); ?> Employees</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="mb-6">
                            <label class="form-label">
                                <i class="fa-solid fa-user text-indigo-400 mr-1"></i> Select Employee
                            </label>
                            <div class="flex items-center gap-3">
                                <select x-model="historyEmployee" class="form-input md:w-96">
                                    <option value="">-- Select Employee --</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name'] . ' (' . $emp['employee_code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <a :href="historyEmployee ? '?view_history=' + historyEmployee : '#'" class="submit-btn" @click.prevent="if(historyEmployee) window.location.href='?view_history=' + historyEmployee">
                                    <i class="fa-solid fa-magnifying-glass"></i> View
                                </a>
                            </div>
                        </div>

                        <?php if ($history_employee_id > 0): ?>
                            <?php if (!empty($history_data)): ?>
                                <!-- Rate Summary Cards -->
                                <?php $latest = $history_data[0]; ?>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                                    <div class="rate-card animate-fade-in-up stagger-1">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-lg bg-emerald-500/15 flex items-center justify-center">
                                                <i class="fa-solid fa-dollar-sign text-emerald-500 text-sm"></i>
                                            </div>
                                            <div>
                                                <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Basic Salary</span>
                                                <p class="text-lg font-extrabold text-emerald-400 mt-0.5"><?php echo $currency; ?> <?php echo number_format($latest['basic_salary'], 2); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="rate-card animate-fade-in-up stagger-2">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-lg bg-blue-500/15 flex items-center justify-center">
                                                <i class="fa-solid fa-calendar-day text-blue-500 text-sm"></i>
                                            </div>
                                            <div>
                                                <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Daily Rate</span>
                                                <p class="text-lg font-extrabold text-blue-400 mt-0.5"><?php echo $currency; ?> <?php echo number_format($latest['daily_rate'] ?? 0, 2); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="rate-card animate-fade-in-up stagger-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-lg bg-indigo-500/15 flex items-center justify-center">
                                                <i class="fa-solid fa-clock text-indigo-500 text-sm"></i>
                                            </div>
                                            <div>
                                                <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Hourly Rate</span>
                                                <p class="text-lg font-extrabold text-indigo-400 mt-0.5"><?php echo $currency; ?> <?php echo number_format($latest['hourly_rate'] ?? 0, 2); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="rate-card animate-fade-in-up stagger-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-lg bg-amber-500/15 flex items-center justify-center">
                                                <i class="fa-solid fa-stopwatch text-amber-500 text-sm"></i>
                                            </div>
                                            <div>
                                                <span class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">OT Rate</span>
                                                <p class="text-lg font-extrabold text-amber-400 mt-0.5"><?php echo $currency; ?> <?php echo number_format($latest['ot_rate'] ?? 0, 2); ?>/hr</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- History Table -->
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-sm whitespace-nowrap">
                                        <thead class="text-white text-xs font-bold uppercase tracking-wider">
                                            <tr>
                                                <th class="px-6 py-4">Effective Date</th>
                                                <th class="px-6 py-4 text-right">Basic Salary</th>
                                                <th class="px-6 py-4 text-right">Daily Rate</th>
                                                <th class="px-6 py-4 text-right">Hourly Rate</th>
                                                <th class="px-6 py-4 text-right">OT Rate</th>
                                                <th class="px-6 py-4 text-center">Status</th>
                                                <th class="px-6 py-4">Created By</th>
                                                <th class="px-6 py-4">Created At</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/[0.06]">
                                            <?php foreach ($history_data as $idx => $h): ?>
                                            <tr class="table-row animate-fade-in-up" style="animation-delay: <?php echo 0.05 + ($idx * 0.03); ?>s;">
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center gap-2">
                                                        <div class="w-8 h-8 rounded-lg bg-emerald-500/15 flex items-center justify-center">
                                                            <i class="fa-solid fa-calendar-day text-emerald-500 text-xs"></i>
                                                        </div>
                                                        <span class="font-semibold text-white"><?php echo date('M d, Y', strtotime($h['effective_date'])); ?></span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 text-right">
                                                    <span class="font-mono font-semibold text-emerald-400"><?php echo $currency; ?> <?php echo number_format($h['basic_salary'], 2); ?></span>
                                                </td>
                                                <td class="px-6 py-4 text-right">
                                                    <span class="font-mono text-blue-400"><?php echo $currency; ?> <?php echo number_format($h['daily_rate'] ?? 0, 2); ?></span>
                                                </td>
                                                <td class="px-6 py-4 text-right">
                                                    <span class="font-mono text-indigo-400"><?php echo $currency; ?> <?php echo number_format($h['hourly_rate'] ?? 0, 2); ?></span>
                                                </td>
                                                <td class="px-6 py-4 text-right">
                                                    <span class="font-mono text-amber-400"><?php echo $currency; ?> <?php echo number_format($h['ot_rate'] ?? 0, 2); ?>/hr</span>
                                                </td>
                                                <td class="px-6 py-4 text-center">
                                                    <?php
                                                    $status = isset($h['is_active']) ? (int)$h['is_active'] : 1;
                                                    ?>
                                                    <span class="status-badge <?php echo $status ? 'status-active' : 'status-inactive'; ?>">
                                                        <i class="fa-solid fa-<?php echo $status ? 'check-circle' : 'times-circle'; ?> text-[10px]"></i>
                                                        <?php echo $status ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span class="text-zinc-400 text-sm"><?php echo htmlspecialchars($h['created_by_name'] ?? $h['created_by'] ?? 'System'); ?></span>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span class="text-zinc-500 text-xs"><?php echo isset($h['created_at']) ? date('M d, Y h:i A', strtotime($h['created_at'])) : '-'; ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-16">
                                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500/10 to-blue-500/10 flex items-center justify-center mx-auto mb-4">
                                        <i class="fa-solid fa-clock-rotate-left text-2xl text-zinc-500"></i>
                                    </div>
                                    <p class="text-zinc-400 font-medium">No salary structure history found for this employee.</p>
                                    <p class="text-zinc-500 text-sm mt-2">Create a salary structure to start tracking changes.</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-16">
                                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-500/10 to-teal-500/10 flex items-center justify-center mx-auto mb-4">
                                    <i class="fa-solid fa-user text-2xl text-zinc-500"></i>
                                </div>
                                <p class="text-zinc-400 font-medium">Select an employee to view history</p>
                                <p class="text-zinc-500 text-sm mt-2">Choose an employee from the dropdown above and click <strong class="text-emerald-400">"View"</strong>.</p>
                            </div>
                        <?php endif; ?>
                    </div>
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
</body>
</html>