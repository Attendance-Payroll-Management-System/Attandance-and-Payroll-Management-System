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

// Check employee status
$is_inactive = validate_employee_active($conn, $employee_id) !== null;

$has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;

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
        $errors = validate_overtime_request($conn, $employee_id, $ot_date, $start_time, $end_time, $reason);

        if (!empty($errors)) {
            $message = implode(' ', $errors);
            $message_type = "error";
        } else {
            $start_ts = strtotime($start_time);
            $end_ts = strtotime($end_time);
            $total_seconds = $end_ts - $start_ts;
            $total_hours = round($total_seconds / 3600, 2);

            if ($has_source) {
                $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason, source) VALUES (?, ?, ?, ?, ?, ?, 'employee_request')");
                $stmt->bind_param('isssds', $employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason);
            } else {
                $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('isssds', $employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason);
            }
            if ($stmt->execute()) {
                create_notification($conn, null, 'ot_request', "$employee_name requested OT on $ot_date (" . number_format($total_hours, 1) . "h)", 'overtimeApproval.php');
                header('Location: overtimerequest.php');
                exit;
            } else {
                $message = "Error submitting overtime request.";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
    }
}

// Handle accept/reject for admin-assigned overtime
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

// Get admin-assigned pending overtime
$admin_ot_result = null;
if ($has_source) {
    $admin_assignments = $conn->prepare("SELECT * FROM overtime_requests WHERE employee_id = ? AND source = 'admin_assigned' AND status = 'Pending' ORDER BY created_at DESC");
    $admin_assignments->bind_param('i', $employee_id);
    $admin_assignments->execute();
    $admin_ot_result = $admin_assignments->get_result();
    $admin_assignments->close();
}

// Get all existing OT requests
$ot_requests = $conn->prepare("SELECT otr.*, e.name as employee_name FROM overtime_requests otr JOIN employee e ON otr.employee_id = e.id WHERE otr.employee_id = ? ORDER BY otr.created_at DESC");
$ot_requests->bind_param('i', $employee_id);
$ot_requests->execute();
$ot_result = $ot_requests->get_result();
$ot_requests->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Overtime Request</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col h-full overflow-y-auto lg:ml-64">
        <header class="glass-strong px-8 py-4 flex items-center justify-between shrink-0 sticky top-0 z-20">
            <div class="animate-fade-in-up">
                <h2 class="text-xl font-bold text-white">Overtime Request</h2>
                <p class="text-xs text-zinc-400"><?php echo format_mmt(mmt_date(), 'l, F j, Y'); ?> (MMT)</p>
            </div>
            <div class="flex items-center gap-4">
                <div class="relative" x-data="{ notifOpen: false }">
                    <button @click="notifOpen = !notifOpen" class="relative p-2 text-zinc-400 hover:text-white glass rounded-full transition">
                        <i class="fa-solid fa-bell text-lg"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="absolute -top-1 -right-1 w-5 h-5 bg-rose-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center shadow-lg shadow-rose-500/30 animate-scale-in"><?php echo $unread_notifications; ?></span>
                        <?php endif; ?>
                    </button>
                    <div x-show="notifOpen" @click.outside="notifOpen = false" class="absolute right-0 mt-2 w-96 glass-strong rounded-xl shadow-xl border border-white/10 z-50" style="display: none;">
                        <div class="p-3 border-b border-white/[0.06] flex items-center justify-between">
                            <h4 class="text-sm font-bold text-white"><i class="fa-regular fa-bell mr-1.5 text-violet-400"></i>Notifications</h4>
                            <?php if ($unread_notifications > 0): ?>
                            <a href="mark_notifications_read.php" class="text-[10px] text-violet-400 hover:text-violet-300 font-semibold transition-colors">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <?php if (empty($notifications)): ?>
                                <p class="p-4 text-xs text-zinc-500 text-center">No notifications</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $noti): ?>
                                    <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-white/[0.04] hover:bg-white/[0.02] transition <?php echo !$noti['is_read'] ? 'bg-violet-500/5' : ''; ?>">
                                        <p class="text-xs text-zinc-300"><?php echo htmlspecialchars($noti['message']); ?></p>
                                        <p class="text-[10px] text-zinc-500 mt-1"><?php echo htmlspecialchars($employee_name) . ' - '; ?><?php echo date('M d, h:i A', strtotime($noti['created_at'])); ?></p>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button onclick="toggleTheme()" class="theme-toggle-btn">
                    <i class="fa-solid fa-sun icon-sun text-base"></i>
                    <i class="fa-solid fa-moon icon-moon text-base"></i>
                </button>
                <div class="flex items-center gap-3 border-l border-white/10 pl-4">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-bold shadow-inner"><?php echo strtoupper(substr($employee_name, 0, 2)); ?></div>
                    <div class="text-right">
                        <h4 class="text-sm font-semibold text-white"><?php echo htmlspecialchars($employee_name); ?></h4>
                        <span class="text-xs text-zinc-400">Employee</span>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-8 space-y-8 flex-1 max-w-[1400px] w-full mx-auto">
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
                            <div class="flex items-center justify-between p-4 bg-amber-500/10 rounded-lg border border-amber-500/20">
                                <div class="flex-1">
                                    <div class="flex items-center gap-4 text-sm">
                                        <span class="font-semibold text-white"><?php echo format_mmt($row['ot_date'], 'M d, Y'); ?></span>
                                        <span class="text-zinc-400 font-mono"><?php echo date('h:i A', strtotime($row['start_time'])); ?> - <?php echo date('h:i A', strtotime($row['end_time'])); ?></span>
                                        <span class="text-indigo-400 font-bold"><?php echo $row['total_hours']; ?>h</span>
                                    </div>
                                    <?php if ($row['reason']): ?>
                                        <p class="text-xs text-zinc-400 mt-1"><?php echo htmlspecialchars($row['reason']); ?></p>
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
                        <?php endwhile; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <h3 class="font-bold text-white mb-4">New Overtime Request</h3>
                    <form method="POST" class="space-y-4 text-zinc-300">
                    <?php echo csrf_field(); ?>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1">OT Date (Calendar Date Picker)</label>
                            <input type="text" name="ot_date" id="ot_date_picker" required readonly
                                   class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500 cursor-pointer"
                                   placeholder="Click to select date...">
                            <p class="text-[10px] text-zinc-500 mt-1">Select a date with completed attendance (checked in & out)</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1">Start Time (MMT)</label>
                                <input type="time" name="start_time" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1">End Time (MMT)</label>
                                <input type="time" name="end_time" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500">
                            </div>
                        </div>
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
                                    <th class="py-3 font-semibold">Start</th>
                                    <th class="py-3 font-semibold">End</th>
                                    <th class="py-3 font-semibold">Hours</th>
                                    <th class="py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                                <?php while ($row = $ot_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="py-3 font-medium text-white"><?php echo format_mmt($row['ot_date'], 'M d, Y'); ?></td>
                                        <td class="py-3 font-mono"><?php echo date('h:i A', strtotime($row['start_time'])); ?></td>
                                        <td class="py-3 font-mono"><?php echo date('h:i A', strtotime($row['end_time'])); ?></td>
                                        <td class="py-3 font-semibold"><?php echo $row['total_hours']; ?>h</td>
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
                                        <td colspan="5" class="py-6 text-center text-zinc-400">No overtime requests yet.</td>
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
// Initialize flatpickr for OT date with only valid dates (attendance exists)
document.addEventListener('DOMContentLoaded', function() {
    // Fetch valid OT dates from server-side (dates with completed attendance)
    const validDates = <?php
        $dates = $conn->prepare("SELECT attendance_date FROM attendance WHERE employee_id = ? AND check_in IS NOT NULL AND check_out IS NOT NULL ORDER BY attendance_date DESC");
        $dates->bind_param('i', $employee_id);
        $dates->execute();
        $date_rows = $dates->get_result()->fetch_all(MYSQLI_ASSOC);
        $dates->close();
        echo json_encode(array_column($date_rows, 'attendance_date'));
    ?>;

    flatpickr('#ot_date_picker', {
        dateFormat: 'Y-m-d',
        minDate: null,
        maxDate: '<?php echo mmt_date(); ?>',
        enable: validDates,
        locale: { firstDayOfWeek: 1 },
        disableMobile: true
    });
});
</script>
</body>
</html>
