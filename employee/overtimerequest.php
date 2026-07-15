<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
require_once "../config/notifications.php";

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

set_mmt_timezone();

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];
$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn, $employee_id);

$is_inactive = validate_employee_active($conn, $employee_id) !== null;

$has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;
$has_request_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'request_type'")->num_rows > 0;
$has_assigned_by = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'assigned_by_id'")->num_rows > 0;
$has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;
$has_ot_pay = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_pay'")->num_rows > 0;
$has_remarks = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'remarks'")->num_rows > 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ot'])) {
    if (!validate_csrf_token()) { $message = "Invalid request."; $message_type = "error"; } else {
    $ot_date = $_POST['ot_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($is_inactive) {
        $message = "Your account is inactive. You cannot submit overtime requests.";
        $message_type = "error";
    } else {
        $errors = validate_overtime_request_rules($conn, $employee_id, $ot_date, $start_time, $end_time, $reason);

        if (!empty($errors)) {
            $message = implode(' ', $errors);
            $message_type = "error";
        } else {
            $start_ts = strtotime($start_time);
            $end_ts = strtotime($end_time);
            $total_hours = round(($end_ts - $start_ts) / 3600, 2);
            $ot_type = detect_overtime_type($conn, $ot_date);
            $ot_rate = get_overtime_rate_for_type($ot_type);
            $ot_pay = calculate_overtime_pay_for_request($conn, $employee_id, $ot_type, $total_hours);

            $cols = ['employee_id', 'ot_date', 'start_time', 'end_time', 'total_hours', 'reason', 'status'];
            $vals = [$employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason, 'Pending'];
            $types = 'isssds';

            if ($has_source)       { $cols[] = 'source';       $vals[] = 'employee_request';  $types .= 's'; }
            if ($has_request_type) { $cols[] = 'request_type'; $vals[] = 'employee_request';  $types .= 's'; }
            if ($has_ot_type)      { $cols[] = 'ot_type';      $vals[] = $ot_type;             $types .= 's'; }
            if ($has_ot_type)      { $cols[] = 'ot_rate';      $vals[] = $ot_rate;             $types .= 'd'; }
            if ($has_ot_pay)       { $cols[] = 'ot_pay';       $vals[] = $ot_pay;              $types .= 'd'; }

            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $conn->prepare("INSERT INTO overtime_requests (" . implode(', ', $cols) . ") VALUES ($placeholders)");
            $stmt->bind_param($types, ...$vals);

            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $stmt->close();

                log_overtime_action($conn, $new_id, 'created', $employee_id, 'employee');

                create_notification($conn, null, 'ot_request', "$employee_name requested OT on $ot_date (" . number_format($total_hours, 1) . "h - " . str_replace('_', ' ', $ot_type) . ")", 'overtimeApproval.php');
                header('Location: overtimerequest.php');
                exit;
            } else {
                $message = "Error submitting overtime request.";
                $message_type = "error";
            }
            if (isset($stmt)) $stmt->close();
        }
    }
    }
}

if ($has_source && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['respond_ot'])) {
    if (!validate_csrf_token()) { $message = "Invalid request."; $message_type = "error"; } else {
    $request_id = $_POST['request_id'] ?? 0;
    $response = $_POST['response'] ?? '';

    if ($request_id > 0 && in_array($response, ['accepted', 'rejected'])) {
        $new_status = $response === 'accepted' ? 'Approved' : 'Rejected';
        $stmt = $conn->prepare("UPDATE overtime_requests SET status = ? WHERE id = ? AND employee_id = ? AND source = 'admin_assigned'");
        $stmt->bind_param('sii', $new_status, $request_id, $employee_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            create_notification($conn, null, 'ot_response', "$employee_name $response OT assignment.", 'overtimeApproval.php');
            if ($new_status === 'Approved' && $has_ot_type) {
                $r_stmt = $conn->prepare("SELECT ot_date, total_hours, start_time, end_time FROM overtime_requests WHERE id = ?");
                $r_stmt->bind_param('i', $request_id);
                $r_stmt->execute();
                $ot_row = $r_stmt->get_result()->fetch_assoc();
                $r_stmt->close();
                if ($ot_row) {
                    $ot_type = detect_overtime_type($conn, $ot_row['ot_date']);
                    $has_ot_rate_col = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_rate'")->num_rows > 0;
                    $has_ot_pay_col = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_pay'")->num_rows > 0;

                    if ($has_ot_rate_col && $has_ot_pay_col) {
                        $ot_rate = get_overtime_rate_for_type($ot_type);
                        $ot_pay = calculate_overtime_pay_for_request($conn, $employee_id, $ot_type, (float)$ot_row['total_hours']);
                        $u_stmt = $conn->prepare("UPDATE overtime_requests SET ot_type = ?, ot_rate = ?, ot_pay = ? WHERE id = ?");
                        $u_stmt->bind_param('sddi', $ot_type, $ot_rate, $ot_pay, $request_id);
                    } else {
                        $u_stmt = $conn->prepare("UPDATE overtime_requests SET ot_type = ? WHERE id = ?");
                        $u_stmt->bind_param('si', $ot_type, $request_id);
                    }
                    $u_stmt->execute();
                    $u_stmt->close();
                }
            }
            log_overtime_action($conn, $request_id, $response === 'accepted' ? 'approved' : 'rejected', $employee_id, 'employee');
            header('Location: overtimerequest.php');
            exit;
        } else {
            $message = "Error updating overtime assignment.";
            $message_type = "error";
        }
        $stmt->close();
    }
    }
}

$notifications = get_notifications($conn, $employee_id, 5);

$admin_ot_result = null;
if ($has_source) {
    $admin_assignments = $conn->prepare("SELECT * FROM overtime_requests WHERE employee_id = ? AND source = 'admin_assigned' AND status = 'Pending' ORDER BY created_at DESC");
    $admin_assignments->bind_param('i', $employee_id);
    $admin_assignments->execute();
    $admin_ot_result = $admin_assignments->get_result();
    $admin_assignments->close();
}

$ot_requests = $conn->prepare("SELECT otr.*, e.name as employee_name FROM overtime_requests otr JOIN employee e ON otr.employee_id = e.id WHERE otr.employee_id = ? ORDER BY otr.created_at DESC");
$ot_requests->bind_param('i', $employee_id);
$ot_requests->execute();
$ot_result = $ot_requests->get_result();
$ot_requests->close();

$monthly_remaining = check_monthly_overtime_remaining($conn, $employee_id, mmt_date());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Overtime Request</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body x-data="otForm()" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Overtime Request"; $page_subtitle = format_mmt(mmt_date(), 'l, F j, Y') . ' (MMT) · <span class="text-purple-400 font-semibold">' . number_format($monthly_remaining['remaining_hours'], 1) . 'h remaining this month</span>'; include "../includes/topbar.php"; ?>

        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full">
            <?php if ($is_inactive): ?>
                <div class="px-4 py-3 rounded-lg border bg-red-500/10 border-red-500/20 text-red-400">
                    <i class="fa-solid fa-ban mr-2"></i> Your account is inactive. You cannot submit overtime requests.
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Monthly Cap Status -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Monthly Cap</span>
                    <p class="text-lg font-bold text-white"><?php echo number_format($monthly_remaining['monthly_max'], 0); ?>h</p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Used</span>
                    <p class="text-lg font-bold text-amber-400"><?php echo number_format($monthly_remaining['used_hours'], 1); ?>h</p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Approved</span>
                    <p class="text-lg font-bold text-emerald-400"><?php echo number_format($monthly_remaining['approved_hours'], 1); ?>h</p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Remaining</span>
                    <p class="text-lg font-bold text-purple-400"><?php echo number_format($monthly_remaining['remaining_hours'], 1); ?>h</p>
                </div>
            </div>

            <?php if ($admin_ot_result && $admin_ot_result->num_rows > 0): ?>
                <div class="glass-strong rounded-2xl border border-amber-500/20 shadow-sm p-5">
                    <div class="flex items-start gap-3 mb-4">
                        <div class="bg-gradient-to-br from-amber-500/20 to-yellow-500/20 text-amber-400 px-3 py-2 rounded-lg"><i class="fa-solid fa-bell"></i></div>
                        <div>
                            <h3 class="font-bold text-white">Pending OT Assignments</h3>
                            <p class="text-xs text-zinc-400">Admin has assigned overtime. Please accept or decline.</p>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <?php while ($row = $admin_ot_result->fetch_assoc()): ?>
                            <div class="p-4 bg-amber-500/10 rounded-lg border border-amber-500/20">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-4 text-sm">
                                            <span class="font-semibold text-white"><?php echo format_mmt($row['ot_date'], 'M d, Y'); ?></span>
                                            <span class="text-zinc-400 font-mono"><?php echo date('h:i A', strtotime($row['start_time'])); ?> - <?php echo date('h:i A', strtotime($row['end_time'])); ?></span>
                                            <span class="text-indigo-400 font-bold"><?php echo $row['total_hours']; ?>h</span>
                                            <?php if ($has_ot_type && isset($row['ot_type'])): ?>
                                                <?php echo get_overtime_type_badge($row['ot_type']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($row['reason']): ?>
                                            <p class="text-xs text-zinc-400 mt-1"><?php echo htmlspecialchars($row['reason']); ?></p>
                                        <?php endif; ?>
                                        <?php if ($has_assigned_by && $row['assigned_by_name']): ?>
                                            <div class="flex items-center gap-3 mt-2 text-xs text-blue-300">
                                                <span><i class="fa-solid fa-user-check mr-1"></i>Assigned by: <strong><?php echo htmlspecialchars($row['assigned_by_name']); ?></strong></span>
                                                <span><?php echo htmlspecialchars($row['assigned_by_position'] ?? ''); ?></span>
                                                <span><?php echo htmlspecialchars($row['assigned_by_department'] ?? ''); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" class="flex gap-2 shrink-0 ml-4">
                                    <?php echo csrf_field(); ?>
                                        <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="respond_ot" value="1" onclick="this.form.response.value='accepted'" class="bg-emerald-500 hover:bg-emerald-600 text-white font-medium text-xs px-4 py-2 rounded-lg transition flex items-center gap-1.5">
                                            <i class="fa-solid fa-check"></i> Accept
                                        </button>
                                        <button type="submit" name="respond_ot" value="1" onclick="this.form.response.value='rejected'" class="border border-red-500/20 hover:bg-red-500/10 text-red-400 font-medium text-xs px-4 py-2 rounded-lg transition flex items-center gap-1.5">
                                            <i class="fa-solid fa-times"></i> Decline
                                        </button>
                                        <input type="hidden" name="response" value="">
                                    </form>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4">New Overtime Request</h3>
                    <form method="POST" class="space-y-4 text-zinc-300" @submit.prevent="submitForm">
                    <?php echo csrf_field(); ?>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1">OT Date</label>
                            <input type="text" name="ot_date" id="ot_date_picker" required readonly
                                   x-model="otDate"
                                   @change="onDateChange"
                                   class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500 cursor-pointer"
                                   placeholder="Click to select date...">
                            <template x-if="otType">
                                <p class="text-xs mt-1" :class="otType === 'working_day' ? 'text-blue-400' : otType === 'weekend' ? 'text-amber-400' : 'text-rose-400'">
                                    <i class="fa-solid fa-tag mr-1"></i>
                                    <span x-text="otType === 'working_day' ? 'Working Day OT (17:00-21:00, max 4h)' : otType === 'weekend' ? 'Weekend OT (09:00-17:00, max 8h)' : 'Holiday OT (09:00-17:00, max 8h)'"></span>
                                </p>
                            </template>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1">Start Time (MMT)</label>
                                <input type="time" name="start_time" required x-model="startTime" @change="calculatePreview"
                                       class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1">End Time (MMT)</label>
                                <input type="time" name="end_time" required x-model="endTime" @change="calculatePreview"
                                       class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500">
                            </div>
                        </div>

                        <!-- Live Preview -->
                        <template x-if="preview.show">
                            <div class="rounded-xl p-4 border" :class="preview.valid ? 'bg-emerald-500/10 border-emerald-500/20' : 'bg-red-500/10 border-red-500/20'">
                                <div class="grid grid-cols-3 gap-3 text-center">
                                    <div>
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Hours</span>
                                        <p class="text-lg font-bold text-white" x-text="preview.hours + 'h'"></p>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Rate</span>
                                        <p class="text-lg font-bold text-purple-400" x-text="'×' + preview.rate"></p>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Est. Pay</span>
                                        <p class="text-lg font-bold text-emerald-400" x-text="'$' + preview.pay"></p>
                                    </div>
                                </div>
                                <p class="text-xs mt-2 text-center" x-text="preview.message" :class="preview.valid ? 'text-emerald-400' : 'text-red-400'"></p>
                            </div>
                        </template>

                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1">Reason for Overtime</label>
                            <textarea name="reason" rows="4" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500 resize-none" placeholder="Explain why overtime is needed..."></textarea>
                        </div>
                        <?php if ($is_inactive): ?>
                            <button type="button" disabled class="w-full bg-zinc-600/50 text-zinc-400 font-semibold text-sm px-4 py-3 rounded-lg cursor-not-allowed flex items-center justify-center gap-2">
                                <i class="fa-solid fa-ban"></i> Account Inactive
                            </button>
                        <?php else: ?>
                            <button type="submit" name="submit_ot" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-3 rounded-lg transition">
                                <i class="fa-solid fa-clock"></i> Submit OT Request
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4">My Overtime Requests</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="py-3 font-semibold">Date</th>
                                    <th class="py-3 font-semibold">Time</th>
                                    <th class="py-3 font-semibold">Hours</th>
                                    <th class="py-3 font-semibold">Type</th>
                                    <th class="py-3 font-semibold">Pay</th>
                                    <th class="py-3 font-semibold">Reason</th>
                                    <th class="py-3 font-semibold">Assigned By</th>
                                    <th class="py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                                <?php while ($row = $ot_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="py-3 font-medium text-white"><?php echo format_mmt($row['ot_date'], 'M d, Y'); ?></td>
                                        <td class="py-3 font-mono text-xs"><?php echo date('h:i A', strtotime($row['start_time'])); ?> - <?php echo date('h:i A', strtotime($row['end_time'])); ?></td>
                                        <td class="py-3 font-semibold"><?php echo $row['total_hours']; ?>h</td>
                                        <td class="py-3">
                                            <?php if ($has_ot_type && $row['ot_type']): ?>
                                                <?php echo get_overtime_type_badge($row['ot_type']); ?>
                                            <?php else: ?>
                                                <span class="text-xs text-zinc-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 font-mono text-sm">
                                            <?php if ($has_ot_pay && isset($row['ot_pay']) && $row['ot_pay'] > 0): ?>
                                                <span class="text-emerald-400 font-semibold">$<?php echo number_format($row['ot_pay'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-zinc-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 text-zinc-400 max-w-[100px] truncate text-xs" title="<?php echo htmlspecialchars($row['reason']); ?>"><?php echo htmlspecialchars($row['reason']); ?></td>
                                        <td class="py-3">
                                            <?php if ($has_assigned_by && $row['assigned_by_name']): ?>
                                                <div class="text-xs">
                                                    <span class="text-blue-300 font-medium"><?php echo htmlspecialchars($row['assigned_by_name']); ?></span>
                                                </div>
                                            <?php elseif (isset($row['source']) && $row['source'] === 'employee_request'): ?>
                                                <span class="text-xs text-zinc-500">Self</span>
                                            <?php else: ?>
                                                <span class="text-xs text-zinc-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold
                                            <?php echo $row['status'] == 'Approved' ? 'bg-emerald-500/20 text-emerald-400' : ''; ?>
                                            <?php echo $row['status'] == 'Rejected' ? 'bg-red-500/20 text-red-400' : ''; ?>
                                            <?php echo $row['status'] == 'Pending' ? 'bg-yellow-500/20 text-yellow-400' : ''; ?>
                                        "><?php echo $row['status']; ?></span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($ot_result->num_rows == 0): ?>
                                    <tr>
                                        <td colspan="8" class="py-6 text-center text-zinc-400">No overtime requests yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
<script>
function otForm() {
    return {
        otDate: '',
        startTime: '',
        endTime: '',
        otType: '',
        preview: { show: false, valid: false, hours: '0.0', rate: '0.02', pay: '0.00', message: '' },
        rateMultipliers: { working_day: 0.02, weekend: 0.03, holiday: 0.04 },
        basicSalary: <?php
            $bs = $conn->query("SELECT basic_salary FROM employee WHERE id = $employee_id")->fetch_assoc();
            echo $bs['basic_salary'] ?? 0;
        ?>,
        onDateChange() {
            if (!this.otDate) { this.otType = ''; return; }
            fetch('../ajax/detect_ot_type.php?date=' + this.otDate)
                .then(r => r.json())
                .then(d => { this.otType = d.type; this.calculatePreview(); })
                .catch(() => { this.otType = ''; });
        },
        calculatePreview() {
            if (!this.otDate || !this.startTime || !this.endTime || !this.otType) {
                this.preview.show = false;
                return;
            }
            const s = new Date('2000-01-01T' + this.startTime + ':00');
            const e = new Date('2000-01-01T' + this.endTime + ':00');
            if (e <= s) { this.preview = { show: true, valid: false, hours: '0.0', rate: '0', pay: '0.00', message: 'End must be after start' }; return; }
            const hours = (e - s) / 3600000;
            const rate = this.rateMultipliers[this.otType] || 0.02;
            const hourlyRate = this.basicSalary > 0 ? (this.basicSalary / (22 * 8)) : 0;
            const pay = hourlyRate * rate * hours;

            const maxH = this.otType === 'working_day' ? 4 : 8;
            const windows = { working_day: { s: '17:00', e: '21:00' }, weekend: { s: '09:00', e: '17:00' }, holiday: { s: '09:00', e: '17:00' } };
            const w = windows[this.otType];
            const valid = hours > 0 && hours <= maxH && this.startTime >= w.s && this.endTime <= w.e;

            this.preview = {
                show: true,
                valid: valid,
                hours: hours.toFixed(1),
                rate: rate.toFixed(2),
                pay: pay.toFixed(2),
                message: valid ? 'Valid OT request ✓' : hours > maxH ? 'Exceeds max ' + maxH + 'h for this type' : this.startTime < w.s || this.endTime > w.e ? 'Outside ' + w.s + '-' + w.e + ' window' : 'Check times'
            };
        },
        submitForm(e) {
            if (!this.preview.valid) {
                if (this.preview.show) {
                    alert(this.preview.message);
                } else {
                    alert('Please select a date and valid time range first.');
                }
                e.preventDefault();
                return;
            }
            e.target.submit();
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    flatpickr('#ot_date_picker', {
        dateFormat: 'Y-m-d',
        minDate: 'today',
        locale: { firstDayOfWeek: 1 },
        disableMobile: true,
        onChange: function(selectedDates, dateStr) {
            const alpineEl = document.querySelector('[x-data]');
            if (alpineEl && alpineEl._x_dataStack) {
                const data = alpineEl._x_dataStack[0];
                data.otDate = dateStr;
                data.onDateChange();
            }
        }
    });
});
</script>
<?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>
