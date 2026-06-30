<?php
require_once '../config/db.php';
require_once '../config/notifications.php';

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn);
$notifications = get_notifications($conn, null, 10);

if (isset($_GET['mark_read'])) {
    mark_notifications_read($conn);
    header('Location: leaveApproval.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $request_id = $_POST['request_id'] ?? 0;
    $action = $_POST['action'] ?? '';

    if ($action == 'approve') {
        $status = 'Approved';
    } elseif ($action == 'reject') {
        $status = 'Rejected';
    }

    if (isset($status) && $request_id > 0) {
        $stmt = $conn->prepare("UPDATE leave_requests SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $request_id);
        if ($stmt->execute()) {
            $message = "Leave request $status successfully.";
            $message_type = "success";

            $req = $conn->prepare("SELECT employee_id, leave_type FROM leave_requests WHERE id = ?");
            $req->bind_param('i', $request_id);
            $req->execute();
            $r = $req->get_result()->fetch_assoc();
            $req->close();

            if ($r) {
                create_notification($conn, $r['employee_id'], 'leave_' . strtolower($status), "Your $r[leave_type] request has been $status.", 'leaverequest.php');
            }
        }
        $stmt->close();
    }
}

$result = $conn->query("
    SELECT lr.*, e.name as employee_name, e.employee_code
    FROM leave_requests lr
    JOIN employee e ON lr.employee_id = e.id
    ORDER BY lr.created_at DESC
");
$requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$pending_count = 0;
foreach ($requests as $r) {
    if ($r['status'] == 'Pending') $pending_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Approval - HRMS Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-gray-50 font-sans flex min-h-screen">

    <aside class="w-64 bg-slate-900 text-slate-200 flex-col hidden md:flex border-r border-slate-800">
        <div class="p-6 border-b border-slate-800">
            <h1 class="text-xl font-bold tracking-wider text-white">HRMS Core</h1>
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2 text-sm">
            <a href="dashboard.php" class="flex items-center px-4 py-3 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition"><i class="fa-solid fa-chart-pie w-5"></i> Dashboard</a>
            <a href="employee.php" class="flex items-center px-4 py-3 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition"><i class="fa-solid fa-users w-5"></i> Employees</a>
            <a href="leaveApproval.php" class="flex items-center px-4 py-3 rounded-lg bg-indigo-600 text-white font-medium relative">
                <i class="fa-solid fa-envelope-open-text w-5"></i> Leave Requests
                <?php if ($pending_count > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="overtimeApproval.php" class="flex items-center px-4 py-3 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition"><i class="fa-solid fa-clock w-5"></i> Overtime Requests</a>
            <a href="payroll.php" class="flex items-center px-4 py-3 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition"><i class="fa-solid fa-coins w-5"></i> Payroll</a>
        </nav>
    </aside>

    <main class="flex-1 p-8">
        <header class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Leave Approvals</h1>
                <p class="text-sm text-slate-500 mt-1">Review and manage employee leave requests.</p>
            </div>
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="relative p-2 text-slate-500 hover:text-slate-700 bg-white rounded-full border border-slate-200">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($unread_notifications > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </button>
                <div x-show="open" @click.outside="open = false" class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-slate-200 z-50" style="display: none;">
                    <div class="p-3 border-b border-slate-100 flex items-center justify-between">
                        <h4 class="text-sm font-bold text-slate-800">Notifications</h4>
                        <a href="?mark_read=1" class="text-xs text-blue-600 font-semibold">Mark all read</a>
                    </div>
                    <div class="max-h-64 overflow-y-auto">
                        <?php if (empty($notifications)): ?>
                            <p class="p-4 text-xs text-slate-400 text-center">No notifications</p>
                        <?php else: ?>
                            <?php foreach ($notifications as $noti): ?>
                            <div class="px-4 py-3 border-b border-slate-50 <?php echo !$noti['is_read'] ? 'bg-blue-50/50' : ''; ?>">
                                <p class="text-xs text-slate-700"><?php echo htmlspecialchars($noti['message']); ?></p>
                                <p class="text-[10px] text-slate-400 mt-1"><?php echo $noti['emp_name'] ? htmlspecialchars($noti['emp_name']) . ' - ' : ''; ?><?php echo date('M d, h:i A', strtotime($noti['created_at'])); ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="mb-4 px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider text-xs border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-4">Employee</th>
                            <th class="px-6 py-4">Leave Type</th>
                            <th class="px-6 py-4">Duration</th>
                            <th class="px-6 py-4">Reason</th>
                            <th class="px-6 py-4">Submitted</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        <?php foreach ($requests as $req): ?>
                        <tr class="<?php echo $req['status'] == 'Pending' ? 'bg-amber-50/30' : ''; ?>">
                            <td class="px-6 py-4">
                                <div class="font-medium text-slate-900"><?php echo htmlspecialchars($req['employee_name']); ?></div>
                                <div class="text-xs text-slate-400"><?php echo htmlspecialchars($req['employee_code']); ?></div>
                            </td>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($req['leave_type']); ?></td>
                            <td class="px-6 py-4">
                                <div class="font-medium"><?php echo date('M d', strtotime($req['start_date'])); ?> - <?php echo date('M d, Y', strtotime($req['end_date'])); ?></div>
                            </td>
                            <td class="px-6 py-4 text-slate-500 max-w-[200px] truncate" title="<?php echo htmlspecialchars($req['reason']); ?>"><?php echo htmlspecialchars($req['reason']); ?></td>
                            <td class="px-6 py-4 text-xs text-slate-400"><?php echo date('M d, h:i A', strtotime($req['created_at'])); ?></td>
                            <td class="px-6 py-4">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold
                                    <?php echo $req['status'] == 'Approved' ? 'bg-green-100 text-green-700' : ''; ?>
                                    <?php echo $req['status'] == 'Rejected' ? 'bg-red-100 text-red-700' : ''; ?>
                                    <?php echo $req['status'] == 'Pending' ? 'bg-yellow-100 text-yellow-700' : ''; ?>
                                "><?php echo $req['status']; ?></span>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <?php if ($req['status'] == 'Pending'): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                    <button type="submit" name="action" value="approve" class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-xs px-3 py-1.5 rounded shadow-sm mr-2 transition">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </button>
                                    <button type="submit" name="action" value="reject" class="border border-red-200 hover:bg-red-50 text-red-600 font-medium text-xs px-3 py-1.5 rounded transition">
                                        <i class="fa-solid fa-times"></i> Reject
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-xs text-slate-400">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($requests)): ?>
                        <tr><td colspan="7" class="px-6 py-8 text-center text-slate-400">No leave requests found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>
