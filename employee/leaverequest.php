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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_leave'])) {
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'];

    if (empty($leave_type) || empty($start_date) || empty($end_date) || empty($reason)) {
        $message = "Please fill in all fields.";
        $message_type = "error";
    } elseif (strtotime($end_date) < strtotime($start_date)) {
        $message = "End date must be after start date.";
        $message_type = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issss', $employee_id, $leave_type, $start_date, $end_date, $reason);
        if ($stmt->execute()) {
            $message = "Leave request submitted successfully.";
            $message_type = "success";
            create_notification($conn, null, 'leave_request', "$employee_name requested $leave_type from $start_date to $end_date", 'leaveApproval.php');
        } else {
            $message = "Error submitting leave request.";
            $message_type = "error";
        }
        $stmt->close();
    }
}

$leaves = $conn->prepare("SELECT lr.*, e.name as employee_name FROM leave_requests lr JOIN employee e ON lr.employee_id = e.id WHERE lr.employee_id = ? ORDER BY lr.created_at DESC");
$leaves->bind_param('i', $employee_id);
$leaves->execute();
$leave_result = $leaves->get_result();
$leaves->close();

$notifications = get_notifications($conn, $employee_id, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Request - HRMS</title>
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
                <a href="leaverequest.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-blue-700 text-white font-medium transition">
                    <i class="fa-solid fa-envelope-open-text w-5 text-center"></i> Leave Request
                </a>
                <a href="overtimerequest.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
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
                <h2 class="text-xl font-bold text-slate-800">Leave Request</h2>
                <p class="text-xs text-slate-500">Submit and track your time-off requests</p>
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
                        <div class="p-2 border-t border-slate-100 text-center">
                            <a href="#" class="text-xs text-blue-600 font-semibold">Mark all as read</a>
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
                    <h3 class="font-bold text-slate-800 mb-4">New Leave Request</h3>
                    <form method="POST" class="space-y-4 text-slate-700">
                        <div>
                            <label class="text-xs font-semibold text-slate-500 block mb-1">Leave Type</label>
                            <select name="leave_type" required class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500 bg-white">
                                <option value="">Select leave type</option>
                                <option value="Annual Leave">Annual Leave</option>
                                <option value="Sick Leave">Sick Leave</option>
                                <option value="Personal Leave">Personal Leave</option>
                                <option value="Maternity Leave">Maternity Leave</option>
                                <option value="Paternity Leave">Paternity Leave</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-slate-500 block mb-1">Start Date</label>
                                <input type="date" name="start_date" required class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-500 block mb-1">End Date</label>
                                <input type="date" name="end_date" required class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-500 block mb-1">Reason</label>
                            <textarea name="reason" rows="4" required class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500 resize-none"></textarea>
                        </div>
                        <button type="submit" name="submit_leave" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-3 rounded-lg transition">
                            <i class="fa-solid fa-paper-plane"></i> Submit Request
                        </button>
                    </form>
                </div>

                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-800 mb-4">My Leave Requests</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="py-3 font-semibold">Type</th>
                                    <th class="py-3 font-semibold">Dates</th>
                                    <th class="py-3 font-semibold">Reason</th>
                                    <th class="py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php while ($row = $leave_result->fetch_assoc()): ?>
                                <tr>
                                    <td class="py-3 font-medium text-slate-900"><?php echo htmlspecialchars($row['leave_type']); ?></td>
                                    <td class="py-3"><?php echo date('M d', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date'])); ?></td>
                                    <td class="py-3 text-slate-500 max-w-[150px] truncate"><?php echo htmlspecialchars($row['reason']); ?></td>
                                    <td class="py-3">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold
                                            <?php echo $row['status'] == 'Approved' ? 'bg-green-100 text-green-700' : ''; ?>
                                            <?php echo $row['status'] == 'Rejected' ? 'bg-red-100 text-red-700' : ''; ?>
                                            <?php echo $row['status'] == 'Pending' ? 'bg-yellow-100 text-yellow-700' : ''; ?>
                                        "><?php echo $row['status']; ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if ($leave_result->num_rows == 0): ?>
                                <tr><td colspan="4" class="py-6 text-center text-slate-400">No leave requests yet.</td></tr>
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
