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
$today = date('Y-m-d');
$current_time = date('H:i:s');
$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn, $employee_id);
$notifications = get_notifications($conn, $employee_id, 5);

// Handle Check In
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_in'])) {
    $check = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $check->bind_param('is', $employee_id, $today);
    $check->execute();
    $check->store_result();

    if ($check->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in, status) VALUES (?, ?, ?, 'present')");
        $stmt->bind_param('iss', $employee_id, $today, $current_time);
        if ($stmt->execute()) {
            $message = "Check-in recorded at " . date('h:i:s A');
            $message_type = "success";
        } else {
            $message = "Error recording check-in.";
            $message_type = "error";
        }
        $stmt->close();
    } else {
        $message = "Already checked in today.";
        $message_type = "error";
    }
    $check->close();
}

// Handle Check Out
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['check_out'])) {
    $check = $conn->prepare("SELECT id, check_out FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $check->bind_param('is', $employee_id, $today);
    $check->execute();
    $result = $check->get_result();
    $att = $result->fetch_assoc();

    if ($att) {
        if ($att['check_out'] === null) {
            $stmt = $conn->prepare("UPDATE attendance SET check_out = ? WHERE id = ?");
            $stmt->bind_param('si', $current_time, $att['id']);
            if ($stmt->execute()) {
                $message = "Check-out recorded at " . date('h:i:s A');
                $message_type = "success";
            } else {
                $message = "Error recording check-out.";
                $message_type = "error";
            }
            $stmt->close();
        } else {
            $message = "Already checked out today.";
            $message_type = "error";
        }
    } else {
        $message = "Please check in first.";
        $message_type = "error";
    }
    $check->close();
}

// Get today's attendance
$today_att = null;
$stmt = $conn->prepare("SELECT check_in, check_out, status FROM attendance WHERE employee_id = ? AND attendance_date = ?");
$stmt->bind_param('is', $employee_id, $today);
$stmt->execute();
$result = $stmt->get_result();
$today_att = $result->fetch_assoc();
$stmt->close();

// Get monthly stats
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

$stats = [];
$stmt = $conn->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days
FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$present_days = $stats['present_days'] ?? 0;
$leave_days = $stats['leave_days'] ?? 0;

// Get monthly OT hours
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as ot_hours FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
$stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$stmt->execute();
$ot_row = $stmt->get_result()->fetch_assoc();
$overtime_hours = $ot_row['ot_hours'] ?? 0;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - HRMS</title>
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
                <a href="attendance.php" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-blue-700 text-white font-medium transition">
                    <i class="fa-solid fa-calendar-check w-5 text-center"></i> Attendance
                </a>
                <a href="leaverequest.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
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
                <h2 class="text-xl font-bold text-slate-800">Good Morning, <?php echo htmlspecialchars($employee_name); ?> <span class="text-amber-500">&#128075;</span></h2>
                <p class="text-xs text-slate-500"><?php echo date('l, F j, Y'); ?></p>
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

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white p-4 rounded-xl border border-slate-200 flex items-center gap-4">
                    <div class="bg-blue-100 text-blue-600 p-4 rounded-xl text-xl"><i class="fa-solid fa-calendar-days"></i></div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block">Present Days</span>
                        <span class="text-2xl font-bold text-slate-800"><?php echo $present_days; ?></span>
                        <span class="text-xs text-blue-600 block font-medium">This Month</span>
                    </div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-slate-200 flex items-center gap-4">
                    <div class="bg-emerald-100 text-emerald-600 p-4 rounded-xl text-xl"><i class="fa-solid fa-plane-departure"></i></div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block">Leave Days</span>
                        <span class="text-2xl font-bold text-slate-800"><?php echo $leave_days; ?></span>
                        <span class="text-xs text-emerald-600 block font-medium">This Month</span>
                    </div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-slate-200 flex items-center gap-4">
                    <div class="bg-purple-100 text-purple-600 p-4 rounded-xl text-xl"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block">Overtime Hours</span>
                        <span class="text-2xl font-bold text-slate-800"><?php echo $overtime_hours; ?></span>
                        <span class="text-xs text-purple-600 block font-medium">Approved This Month</span>
                    </div>
                </div>
                <div class="bg-white p-4 rounded-xl border border-slate-200 flex items-center gap-4">
                    <div class="bg-orange-100 text-orange-600 p-4 rounded-xl text-xl"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block">Today</span>
                        <span class="text-2xl font-bold text-slate-800">
                            <?php
                            if ($today_att && $today_att['check_in'] && $today_att['check_out']) echo 'Done';
                            elseif ($today_att && $today_att['check_in']) echo 'Working';
                            else echo '--';
                            ?>
                        </span>
                        <span class="text-xs text-slate-500 block font-medium">
                            <?php
                            if ($today_att && $today_att['check_in']) {
                                echo 'In: ' . date('h:i A', strtotime($today_att['check_in']));
                                if ($today_att['check_out']) echo ' | Out: ' . date('h:i A', strtotime($today_att['check_out']));
                            }
                            ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                    <div>
                        <div class="flex items-start gap-3 mb-4">
                            <div class="bg-blue-100 text-blue-600 px-3 py-2 rounded-lg"><i class="fa-solid fa-fingerprint"></i></div>
                            <div>
                                <h3 class="font-bold text-slate-800">Daily Attendance</h3>
                                <p class="text-xs text-slate-400">Mark your check in and check out</p>
                            </div>
                        </div>
                        <div class="space-y-3 text-slate-700">
                            <div>
                                <label class="text-xs font-semibold text-slate-500 block mb-1">Date</label>
                                <input type="date" value="<?php echo $today; ?>" disabled class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg bg-slate-50 text-slate-500">
                            </div>
                            <div class="flex gap-3">
                                <?php if (!$today_att || !$today_att['check_in']): ?>
                                    <form method="POST" class="flex-1">
                                        <button type="submit" name="check_in" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-3 rounded-lg transition flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-arrow-right-to-bracket"></i> Check In
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($today_att && $today_att['check_in'] && !$today_att['check_out']): ?>
                                    <form method="POST" class="flex-1">
                                        <button type="submit" name="check_out" class="w-full bg-orange-500 hover:bg-orange-600 text-white font-semibold text-sm px-4 py-3 rounded-lg transition flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-arrow-left-from-bracket"></i> Check Out
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($today_att && $today_att['check_in'] && $today_att['check_out']): ?>
                                    <div class="w-full bg-green-50 text-green-700 font-semibold text-sm px-4 py-3 rounded-lg border border-green-200 text-center">
                                        <i class="fa-solid fa-check-circle"></i> Completed
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2 bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-800 mb-4">Recent Attendance</h3>
                    <?php
                    $recent = $conn->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 10");
                    $recent->bind_param('i', $employee_id);
                    $recent->execute();
                    $recent_result = $recent->get_result();
                    ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-slate-500 text-xs uppercase tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="py-3 font-semibold">Date</th>
                                    <th class="py-3 font-semibold">Check In</th>
                                    <th class="py-3 font-semibold">Check Out</th>
                                    <th class="py-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php while ($row = $recent_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="py-3 font-medium text-slate-900"><?php echo date('M d, Y', strtotime($row['attendance_date'])); ?></td>
                                        <td class="py-3 font-mono"><?php echo $row['check_in'] ? date('h:i:s A', strtotime($row['check_in'])) : '-'; ?></td>
                                        <td class="py-3 font-mono"><?php echo $row['check_out'] ? date('h:i:s A', strtotime($row['check_out'])) : '-'; ?></td>
                                        <td class="py-3">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold
                                            <?php echo $row['status'] == 'present' ? 'bg-green-100 text-green-700' : ''; ?>
                                            <?php echo $row['status'] == 'late' ? 'bg-yellow-100 text-yellow-700' : ''; ?>
                                            <?php echo $row['status'] == 'leave' ? 'bg-blue-100 text-blue-700' : ''; ?>
                                            <?php echo $row['status'] == 'absent' ? 'bg-red-100 text-red-700' : ''; ?>
                                        "><?php echo ucfirst($row['status']); ?></span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($recent_result->num_rows == 0): ?>
                                    <tr>
                                        <td colspan="4" class="py-6 text-center text-slate-400">No attendance records yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php $recent->close(); ?>
                </div>
            </div>
        </main>
    </div>
</body>

</html>