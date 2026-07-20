<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';
require_once '../config/notifications.php';

set_mmt_timezone();

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn);
$notifications = get_notifications($conn, null, 10);

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'];
$admin_department = $_SESSION['admin_department'] ?? '';
$admin_position = $_SESSION['admin_position'] ?? '';

// Fetch departments
$departments = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);

// Fetch employees grouped by department
$employees = $conn->query("
    SELECT e.id, e.name, e.employee_code, e.department_id, d.department_name, p.position_name
    FROM employee e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN positions p ON e.position_id = p.id
    WHERE e.status = 'active'
    ORDER BY d.department_name, e.name
")->fetch_all(MYSQLI_ASSOC);

// Build department -> employee count mapping
$dept_emp_count = [];
foreach ($employees as $emp) {
    $did = $emp['department_id'] ?? 0;
    if (!isset($dept_emp_count[$did])) $dept_emp_count[$did] = 0;
    $dept_emp_count[$did]++;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_ot'])) {
    if (!validate_csrf_token()) {
        $message = 'Invalid request.';
        $message_type = 'error';
    } else {
        $assignment_type = $_POST['assignment_type'] ?? 'employee';
        $ot_date = $_POST['ot_date'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        $data = [
            'assignment_type' => $assignment_type,
            'department_id' => $assignment_type === 'department' ? (int)($_POST['department_id'] ?? 0) : null,
            'employee_id' => $assignment_type === 'employee' ? (int)($_POST['employee_id'] ?? 0) : null,
            'assigned_by' => $admin_id,
            'assigned_by_name' => $admin_name,
            'assigned_by_position' => $admin_position,
            'ot_date' => $ot_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'reason' => $reason,
        ];

        // Validate required fields
        if (empty($ot_date) || empty($start_time) || empty($end_time)) {
            $message = 'Please fill in all required fields.';
            $message_type = 'error';
        } elseif ($assignment_type === 'department' && empty($data['department_id'])) {
            $message = 'Please select a department.';
            $message_type = 'error';
        } elseif ($assignment_type === 'employee' && empty($data['employee_id'])) {
            $message = 'Please select an employee.';
            $message_type = 'error';
        } else {
            // Detect OT type and validate time rules
            $ot_type = detect_overtime_type($conn, $ot_date);
            $time_rules = validate_overtime_time_rules($ot_type, $start_time, $end_time);

            if (!$time_rules['valid']) {
                $message = implode(' ', $time_rules['errors']);
                $message_type = 'error';
            } else {
                $assignment_id = create_overtime_assignment($conn, $data);

                if ($assignment_id) {
                    // Get the assignment code and final status
                    $code_stmt = $conn->prepare("SELECT assignment_code, status FROM overtime_assignments WHERE id = ?");
                    $code_stmt->bind_param('i', $assignment_id);
                    $code_stmt->execute();
                    $code_row = $code_stmt->get_result()->fetch_assoc();
                    $code_stmt->close();
                    $code = $code_row['assignment_code'] ?? "OTA-$assignment_id";
                    $final_status = $code_row['status'] ?? 'Assigned';

                    $message = "Overtime assignment $code created successfully.";
                    $message_type = 'success';

                    log_activity($conn, $admin_id, 'overtime_assignment_created', "Created overtime assignment $code for " . ($assignment_type === 'department' ? 'department' : 'employee') . " on $ot_date");

                    // Send notifications only if assignment is not auto-cancelled
                    if ($final_status !== 'Cancelled' && $assignment_type === 'department' && !empty($data['department_id'])) {
                        $dept_id = (int)$data['department_id'];
                        $date_display = date('M d, Y', strtotime($ot_date));
                        $time_display = date('h:i A', strtotime($start_time)) . ' - ' . date('h:i A', strtotime($end_time));
                        $emp_link = '../employee/overtimerequest.php';
                        $admin_link = "assignment_detail.php?id=$assignment_id";

                        // Get department name
                        $dept_stmt = $conn->prepare("SELECT department_name FROM departments WHERE id = ?");
                        $dept_stmt->bind_param('i', $dept_id);
                        $dept_stmt->execute();
                        $dept_name = $dept_stmt->get_result()->fetch_assoc()['department_name'] ?? 'your department';
                        $dept_stmt->close();

                        // Get all active employees in the department
                        $emp_stmt = $conn->prepare("SELECT id, name FROM employee WHERE department_id = ? AND status = 'active'");
                        $emp_stmt->bind_param('i', $dept_id);
                        $emp_stmt->execute();
                        $dept_employees = $emp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $emp_stmt->close();

                        // Find department manager (first active employee in the dept)
                        $mgr_stmt = $conn->prepare("SELECT id, name FROM employee WHERE department_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
                        $mgr_stmt->bind_param('i', $dept_id);
                        $mgr_stmt->execute();
                        $manager = $mgr_stmt->get_result()->fetch_assoc();
                        $mgr_stmt->close();

                        // Notify each employee
                        foreach ($dept_employees as $emp) {
                            $msg = "You have been assigned overtime on $date_display ($time_display, {$total_hours}h) by $admin_name." . ($reason ? " Reason: $reason" : '');
                            create_notification($conn, (int)$emp['id'], 'ot_assigned', $msg, $emp_link);
                        }

                        // Notify the department manager (if found and not already in the employee list notification)
                        if ($manager) {
                            $mgr_msg = "Overtime has been assigned to $dept_name on $date_display ($time_display, {$total_hours}h) by $admin_name." . ($reason ? " Reason: $reason" : '');
                            create_notification($conn, (int)$manager['id'], 'ot_assigned', $mgr_msg, $admin_link);
                        }

                        // Notify admin (global notification)
                        $admin_msg = "Overtime assignment $code created for $dept_name ($date_display, $time_display). " . count($dept_employees) . " employee(s) assigned.";
                        create_notification($conn, null, 'ot_assigned', $admin_msg, $admin_link);
                    }
                } else {
                    $message = 'Error creating overtime assignment. Please try again.';
                    $message_type = 'error';
                }
            }
        }
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

<body x-data="assignOvertime()" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Assign Overtime";
        $page_subtitle = "Assign overtime to departments or individual employees.";
        include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Assignment Form -->
                <div class="lg:col-span-2">
                    <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="text-lg font-bold text-white mb-6"><i class="fa-solid fa-clock text-blue-400 mr-2"></i>New Overtime Assignment</h2>

                        <form method="POST" id="assignForm" class="space-y-5 text-zinc-300">
                            <?php echo csrf_field(); ?>

                            <!-- Assignment Type Toggle -->
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-2">Assignment Type</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <button type="button" @click="assignmentType = 'department'" :class="assignmentType === 'department' ? 'bg-blue-600/20 border-blue-500 text-blue-400' : 'bg-white/[0.06] border-white/10 text-zinc-400 hover:border-blue-400/50'" class="rounded-xl border px-4 py-3 text-sm font-semibold transition flex items-center justify-center gap-2">
                                        <i class="fa-solid fa-building"></i> Department
                                    </button>
                                    <button type="button" @click="assignmentType = 'employee'" :class="assignmentType === 'employee' ? 'bg-blue-600/20 border-blue-500 text-blue-400' : 'bg-white/[0.06] border-white/10 text-zinc-400 hover:border-blue-400/50'" class="rounded-xl border px-4 py-3 text-sm font-semibold transition flex items-center justify-center gap-2">
                                        <i class="fa-solid fa-user"></i> Employee
                                    </button>
                                </div>
                                <input type="hidden" name="assignment_type" :value="assignmentType">
                            </div>

                            <!-- Department Selection -->
                            <div x-show="assignmentType === 'department'" x-transition>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Select Department</label>
                                <select name="department_id" x-model="selectedDeptId" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                    <option value="">-- Select Department --</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['department_name']); ?> (<?php echo $dept_emp_count[$dept['id']] ?? 0; ?> employees)</option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-zinc-500 mt-1.5" x-show="selectedDeptId">
                                    <i class="fa-solid fa-users mr-1"></i>
                                    <span x-text="deptEmpCount[selectedDeptId] + ' active employees will be assigned'"></span>
                                </p>
                            </div>

                            <!-- Employee Selection -->
                            <div x-show="assignmentType === 'employee'" x-transition>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Select Employee</label>
                                <select name="employee_id" x-model="selectedEmpId" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                    <option value="">-- Select Employee --</option>
                                    <?php
                                    $current_dept = '';
                                    foreach ($employees as $emp):
                                        $did = $emp['department_id'] ?? 0;
                                        if ($emp['department_name'] !== $current_dept):
                                            if ($current_dept !== '') echo '</optgroup>';
                                            $current_dept = $emp['department_name'] ?? 'Unassigned';
                                            echo '<optgroup label="' . htmlspecialchars($current_dept) . '">';
                                        endif;
                                    ?>
                                        <option value="<?php echo $emp['id']; ?>" data-dept="<?php echo $did; ?>">
                                            <?php echo htmlspecialchars($emp['name'] . ' (' . $emp['employee_code'] . ') - ' . ($emp['position_name'] ?? 'N/A')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if ($current_dept !== '') echo '</optgroup>'; ?>
                                </select>
                            </div>

                            <!-- OT Date -->
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">OT Date</label>
                                <input type="date" name="ot_date" x-model="otDate" @change="validateAssignment()" min="<?php echo mmt_date(); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            </div>

                            <!-- Time Range -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Start Time</label>
                                    <input type="time" name="start_time" x-model="startTime" @change="validateAssignment()" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-zinc-400 block mb-1.5">End Time</label>
                                    <input type="time" name="end_time" x-model="endTime" @change="validateAssignment()" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                </div>
                            </div>

                            <!-- Reason -->
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Reason / Instructions</label>
                                <textarea name="reason" rows="3" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30 resize-none" placeholder="Describe the overtime task..."></textarea>
                            </div>

                            <button type="submit" name="assign_ot" :disabled="!isValid || validating" class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                                <i class="fa-solid fa-clock" x-show="!validating"></i>
                                <i class="fa-solid fa-spinner fa-spin" x-show="validating"></i>
                                <span x-text="validating ? 'Validating...' : 'Assign Overtime'"></span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Validation Preview Panel -->
                <div class="lg:col-span-1 space-y-4">
                    <!-- OT Type Detection -->
                    <div class="card-hover glass-strong rounded-2xl p-5">
                        <h3 class="font-bold text-white mb-3 text-sm"><i class="fa-solid fa-wand-magic-sparkles text-purple-400 mr-2"></i>OT Type Detection</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-zinc-400">Type:</span>
                                <span x-text="otTypeLabel || '-' " :class="otTypeClass" class="font-semibold"></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-zinc-400">Total Hours:</span>
                                <span x-text="totalHours > 0 ? totalHours + 'h' : '-'" class="text-white font-semibold"></span>
                            </div>
                            <div class="flex justify-between text-sm" x-show="otType === 'working_day'">
                                <span class="text-zinc-400">Allowed Window:</span>
                                <span class="text-zinc-300 text-xs">5:00 PM - 9:00 PM</span>
                            </div>
                            <div class="flex justify-between text-sm" x-show="otType === 'weekend' || otType === 'holiday'">
                                <span class="text-zinc-400">Allowed Window:</span>
                                <span class="text-zinc-300 text-xs">9:00 AM - 5:00 PM</span>
                            </div>
                        </div>
                    </div>

                    <!-- Validation Status -->
                    <div class="card-hover glass-strong rounded-2xl p-5">
                        <h3 class="font-bold text-white mb-3 text-sm"><i class="fa-solid fa-shield-check text-emerald-400 mr-2"></i>Validation Status</h3>
                        <div x-show="validationErrors.length === 0 && !validating && (selectedDeptId || selectedEmpId) && otDate && startTime && endTime" class="text-center py-3">
                            <div class="w-12 h-12 rounded-full bg-emerald-500/20 flex items-center justify-center mx-auto mb-2">
                                <i class="fa-solid fa-check text-emerald-400 text-xl"></i>
                            </div>
                            <p class="text-emerald-400 text-sm font-semibold">All validations passed</p>
                        </div>
                        <div x-show="validationErrors.length > 0" class="space-y-2">
                            <template x-for="err in validationErrors" :key="err">
                                <div class="flex items-start gap-2 text-xs">
                                    <i class="fa-solid fa-circle-exclamation text-red-400 mt-0.5 shrink-0"></i>
                                    <span class="text-red-400" x-text="err"></span>
                                </div>
                            </template>
                        </div>
                        <div x-show="validationErrors.length === 0 && !validating && !(selectedDeptId || selectedEmpId && otDate && startTime && endTime)" class="text-center py-3">
                            <p class="text-zinc-500 text-xs">Select assignment type, date, and time to validate.</p>
                        </div>
                    </div>

                    <!-- Employee Results (Department mode) -->
                    <div class="card-hover glass-strong rounded-2xl p-5" x-show="assignmentType === 'department' && eligibleCount > 0">
                        <h3 class="font-bold text-white mb-3 text-sm"><i class="fa-solid fa-users text-blue-400 mr-2"></i>Employee Validation</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-emerald-400 font-semibold">Eligible</span>
                                <span class="text-emerald-400 font-bold" x-text="eligibleCount"></span>
                            </div>
                            <div class="flex justify-between text-sm" x-show="ineligibleCount > 0">
                                <span class="text-red-400 font-semibold">Ineligible</span>
                                <span class="text-red-400 font-bold" x-text="ineligibleCount"></span>
                            </div>
                        </div>
                        <div x-show="ineligibleEmployees.length > 0" class="mt-3 space-y-1.5 max-h-48 overflow-y-auto">
                            <template x-for="emp in ineligibleEmployees" :key="emp.id">
                                <div class="bg-red-500/10 rounded-lg px-3 py-2 border border-red-500/20">
                                    <div class="text-xs font-semibold text-red-400" x-text="emp.name + ' (' + emp.employee_code + ')'"></div>
                                    <div class="text-[10px] text-red-400/70" x-text="emp.errors.join('; ')"></div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Monthly Limit Info (Employee mode) -->
                    <div class="card-hover glass-strong rounded-2xl p-5" x-show="assignmentType === 'employee' && monthlyInfo">
                        <h3 class="font-bold text-white mb-3 text-sm"><i class="fa-solid fa-gauge-high text-amber-400 mr-2"></i>Monthly OT Limit</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-zinc-400">Current Used:</span>
                                <span class="text-white font-semibold" x-text="monthlyInfo.current_hours + 'h'"></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-zinc-400">New OT:</span>
                                <span class="text-white font-semibold" x-text="totalHours + 'h'"></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-zinc-400">Total:</span>
                                <span :class="monthlyInfo.within_limit ? 'text-emerald-400' : 'text-red-400'" class="font-bold" x-text="monthlyInfo.new_total + 'h / ' + monthlyInfo.max + 'h'"></span>
                            </div>
                            <div class="h-2 bg-white/[0.06] rounded-full overflow-hidden mt-1">
                                <div class="h-full rounded-full transition-all" :class="monthlyInfo.new_total > monthlyInfo.max ? 'bg-red-500' : (monthlyInfo.new_total > monthlyInfo.max * 0.8 ? 'bg-amber-500' : 'bg-emerald-500')" :style="'width: ' + Math.min(100, (monthlyInfo.new_total / monthlyInfo.max) * 100) + '%'"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Rate Info -->
                    <div class="card-hover glass-strong rounded-2xl p-5">
                        <h3 class="font-bold text-white mb-3 text-sm"><i class="fa-solid fa-percent text-cyan-400 mr-2"></i>OT Rate Reference</h3>
                        <div class="space-y-2 text-xs">
                            <div class="flex justify-between"><span class="text-blue-400">Working Day</span><span class="text-zinc-400">0.02</span></div>
                            <div class="flex justify-between"><span class="text-amber-400">Weekend</span><span class="text-zinc-400">0.03</span></div>
                            <div class="flex justify-between"><span class="text-rose-400">Holiday</span><span class="text-zinc-400">0.04</span></div>
                        </div>
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

    <script>
        function assignOvertime() {
            return {
                assignmentType: 'employee',
                selectedDeptId: '',
                selectedEmpId: '',
                otDate: '',
                startTime: '',
                endTime: '',
                otType: '',
                otTypeLabel: '',
                totalHours: 0,
                isValid: false,
                validating: false,
                validationErrors: [],
                eligibleCount: 0,
                ineligibleCount: 0,
                eligibleEmployees: [],
                ineligibleEmployees: [],
                monthlyInfo: null,
                deptEmpCount: <?php echo json_encode($dept_emp_count); ?>,

                get otTypeClass() {
                    if (this.otType === 'working_day') return 'text-blue-400';
                    if (this.otType === 'weekend') return 'text-amber-400';
                    if (this.otType === 'holiday') return 'text-rose-400';
                    return 'text-zinc-400';
                },

                validateAssignment() {
                    if (!this.otDate || !this.startTime || !this.endTime) {
                        this.isValid = false;
                        this.validationErrors = [];
                        return;
                    }

                    const hasTarget = (this.assignmentType === 'department' && this.selectedDeptId) ||
                                      (this.assignmentType === 'employee' && this.selectedEmpId);
                    if (!hasTarget) {
                        this.isValid = false;
                        return;
                    }

                    this.validating = true;

                    fetch('../ajax/validate_overtime_assignment.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            assignment_type: this.assignmentType,
                            department_id: this.assignmentType === 'department' ? this.selectedDeptId : null,
                            employee_id: this.assignmentType === 'employee' ? this.selectedEmpId : null,
                            ot_date: this.otDate,
                            start_time: this.startTime,
                            end_time: this.endTime
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        this.validating = false;
                        this.otType = data.ot_type || '';
                        this.otTypeLabel = data.ot_type_label || '';
                        this.totalHours = data.total_hours || 0;
                        this.validationErrors = data.errors || [];
                        this.isValid = data.valid || false;

                        if (this.assignmentType === 'department') {
                            this.eligibleCount = data.eligible_count || 0;
                            this.ineligibleCount = data.ineligible_count || 0;
                            this.eligibleEmployees = data.employees || [];
                            this.ineligibleEmployees = data.ineligible_employees || [];
                        }

                        if (this.assignmentType === 'employee' && data.monthly_info) {
                            this.monthlyInfo = data.monthly_info;
                        } else {
                            this.monthlyInfo = null;
                        }
                    })
                    .catch(err => {
                        this.validating = false;
                        this.validationErrors = ['Validation request failed. Please try again.'];
                        this.isValid = false;
                    });
                },

                // Watch for changes
                init() {
                    this.$watch('selectedDeptId', () => this.validateAssignment());
                    this.$watch('selectedEmpId', () => this.validateAssignment());
                    this.$watch('assignmentType', () => {
                        this.validationErrors = [];
                        this.isValid = false;
                        this.eligibleCount = 0;
                        this.ineligibleCount = 0;
                        this.ineligibleEmployees = [];
                        this.monthlyInfo = null;
                        this.validateAssignment();
                    });
                }
            }
        }
    </script>
</body>

</html>
