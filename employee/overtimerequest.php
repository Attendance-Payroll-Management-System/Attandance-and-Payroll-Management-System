<?php
session_start();
require_once "../config/db.php";
require_once "../config/notifications.php";

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];
$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn, $employee_id);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ot'])) {
    $ot_date = $_POST['ot_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $reason = $_POST['reason'];

    // Validate attendance exists for this date
    $check_att = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ? AND check_in IS NOT NULL AND check_out IS NOT NULL");
    $check_att->bind_param('is', $employee_id, $ot_date);
    $check_att->execute();
    $check_att->store_result();

    if ($check_att->num_rows == 0) {
        $message = "Cannot request overtime. No completed attendance found for this date.";
        $message_type = "error";
    } elseif (empty($ot_date) || empty($start_time) || empty($end_time) || empty($reason)) {
        $message = "Please fill in all fields.";
        $message_type = "error";
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $message = "End time must be after start time.";
        $message_type = "error";
    } else {
        // Calculate total hours
        $start_ts = strtotime($start_time);
        $end_ts = strtotime($end_time);
        $total_seconds = $end_ts - $start_ts;
        $total_hours = round($total_seconds / 3600, 2);

        $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_date, start_time, end_time, total_hours, reason) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssds', $employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason);
        if ($stmt->execute()) {
            $message = "Overtime request submitted successfully. Total hours: " . number_format($total_hours, 2);
            $message_type = "success";
            create_notification($conn, null, 'ot_request', "$employee_name requested OT on $ot_date (" . number_format($total_hours, 1) . "h)", 'overtimeApproval.php');
        } else {
            $message = "Error submitting overtime request.";
            $message_type = "error";
        }
        $stmt->close();
    }
    $check_att->close();
}

$notifications = get_notifications($conn, $employee_id, 5);

// Get dates where attendance exists (checked in AND checked out)
$att_dates = $conn->prepare("SELECT attendance_date, check_in, check_out FROM attendance WHERE employee_id = ? AND check_in IS NOT NULL AND check_out IS NOT NULL ORDER BY attendance_date DESC");
$att_dates->bind_param('i', $employee_id);
$att_dates->execute();
$att_dates_result = $att_dates->get_result();
$att_dates->close();

// Get existing OT requests
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
    <title>Overtime Request - HRMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body class="bg-slate-100 font-sans antialiased flex h-screen overflow-hidden">

    <aside class="w-64 bg-blue-900 text-white flex flex-col justify-between p-4 shrink-0">
        <div>
            <div class="flex items-center gap-3 px-2 py-4 border-b border-blue-800 mb-6">
                <div class="bg-blue-600 p-2 rounded-lg text-xl"><i class="fa-solid fa-users"></i></div>
                <div>
                    <h1 class="font-bold text-lg leading-none">HRMS</h1>
                    <span class="text-xs text-blue-300">Employee Portal</span>
                </div>
            </div>
            <nav class="space-y-1">
                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-house w-5 text-center"></i> Dashboard
                </a>
                <a href="attendance.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-calendar-check w-5 text-center"></i> Attendance
                </a>
                <a href="leaverequest.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-envelope-open-text w-5 text-center"></i> Leave Request
                </a>
                <a href="overtimerequest.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-blue-700 text-white font-medium transition">
                    <i class="fa-solid fa-clock-rotate-left w-5 text-center"></i> Overtime Request
                </a>
                <a href="attendanceall.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-folder-open w-5 text-center"></i> My Records
                </a>
            </nav>
        </div>
        <a href="login.php?logout=1" class="flex items-center gap-3 px-4 py-3 rounded-lg text-red-300 hover:bg-red-900/50 hover:text-red-200 transition">
            <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Logout
        </a>
    </aside>

    <div class="flex-1 flex flex-col h-full overflow-y-auto">
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between shrink-0">
            <div>
                <h2 class="text-xl font-bold text-slate-800">Overtime Request</h2>
                <p class="text-xs text-slate-500">Submit overtime for days you have attended</p>
            </div>
            <div class="flex items-center gap-4">
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="relative p-2 text-slate-500 hover:text-slate-700 bg-slate-100 rounded-full">
                        <i class="fa-solid fa-bell text-lg"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"><?php echo $unread_notifications; ?></span>
                        <?php endif; ?>
                    </button>
                    <div x-show="open" @click.outside="open = false" class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-slate-200 z-50" style="display: none;">
                        <div class="p-3 border-b border-slate-100">
                            <h4 class="text-sm font-bold text-slate-800">Notifications</h4>
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            <?php if (empty($notifications)): ?>
                                <p class="p-4 text-xs text-slate-400 text-center">No notifications</p>
                            <?php else: ?>
                                <?php foreach ($notifications as $noti): ?>
                                    <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-slate-50 hover:bg-slate-50 transition <?php echo !$noti['is_read'] ? 'bg-blue-50/50' : ''; ?>">
                                        <p class="text-xs text-slate-700"><?php echo htmlspecialchars($noti['message']); ?></p>
                                        <p class="text-[10px] text-slate-400 mt-1"><?php echo date('M d, h:i A', strtotime($noti['created_at'])); ?></p>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 border-l border-slate-200 pl-4">
                    <div class="text-right">
                        <h4 class="text-sm font-semibold text-slate-800"><?php echo htmlspecialchars($employee_name); ?></h4>
                        <span class="text-xs text-slate-400">Employee</span>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-6 space-y-6">
            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-800 mb-4">New Overtime Request</h3>
                    <form method="POST" class="space-y-4 text-slate-700">
                        <div>
                            <label class="text-xs font-semibold text-slate-500 block mb-1">OT Date (select a date you attended)</label>
                            <select name="ot_date" required class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500 bg-white">
                                <option value="">-- Select date --</option>
                                <?php while ($row = $att_dates_result->fetch_assoc()): ?>
                                    <option value="<?php echo $row['attendance_date']; ?>">
                                        <?php echo date('M d, Y (D)', strtotime($row['attendance_date'])); ?>
                                        (In: <?php echo date('h:i A', strtotime($row['check_in'])); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <?php if ($att_dates_result->num_rows == 0): ?>
                                <p class="text-xs text-amber-600 mt-1">No completed attendance records found. Please check in/out first.</p>
                            <?php endif; ?>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-slate-500 block mb-1">Start Time</label>
                                <input type="time" name="start_time" required class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-500 block mb-1">End Time</label>
                                <input type="time" name="end_time" required class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-500 block mb-1">Reason for Overtime</label>
                            <textarea name="reason" rows="4" required class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500 resize-none" placeholder="Explain why overtime is needed..."></textarea>
                        </div>
                        <button type="submit" name="submit_ot" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-3 rounded-lg transition">
                            <i class="fa-solid fa-clock"></i> Submit OT Request
                        </button>
                    </form>
                </div>

                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-800 mb-4">My Overtime Requests</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="py-3 font-semibold">Date</th>
                                    <th class="py-3 font-semibold">Start</th>
                                    <th class="py-3 font-semibold">End</th>
                                    <th class="py-3 font-semibold">Hours</th>
                                    <th class="py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php while ($row = $ot_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="py-3 font-medium text-slate-900"><?php echo date('M d, Y', strtotime($row['ot_date'])); ?></td>
                                        <td class="py-3 font-mono"><?php echo date('h:i A', strtotime($row['start_time'])); ?></td>
                                        <td class="py-3 font-mono"><?php echo date('h:i A', strtotime($row['end_time'])); ?></td>
                                        <td class="py-3 font-semibold"><?php echo $row['total_hours']; ?>h</td>
                                        <td class="py-3">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold
                                            <?php echo $row['status'] == 'Approved' ? 'bg-green-100 text-green-700' : ''; ?>
                                            <?php echo $row['status'] == 'Rejected' ? 'bg-red-100 text-red-700' : ''; ?>
                                            <?php echo $row['status'] == 'Pending' ? 'bg-yellow-100 text-yellow-700' : ''; ?>
                                        "><?php echo $row['status']; ?></span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($ot_result->num_rows == 0): ?>
                                    <tr>
                                        <td colspan="5" class="py-6 text-center text-slate-400">No overtime requests yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>

</html>