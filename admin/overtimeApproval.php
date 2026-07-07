<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';
require_once '../config/notifications.php';

$message = '';
$message_type = '';
$unread_notifications = get_unread_count($conn);
$notifications = get_notifications($conn, null, 10);

if (isset($_GET['mark_read'])) {
    mark_notifications_read($conn);
    header('Location: overtimeApproval.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) { http_response_code(403); exit('CSRF validation failed.'); }
    $request_id = $_POST['request_id'] ?? 0;
    $action = $_POST['action'] ?? '';

    if ($action == 'approve') {
        $status = 'Approved';
    } elseif ($action == 'reject') {
        $status = 'Rejected';
    }

    if (isset($status) && $request_id > 0) {
        $stmt = $conn->prepare("UPDATE overtime_requests SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $request_id);
        if ($stmt->execute()) {
            $message = "Overtime request $status successfully.";
            $message_type = "success";

            $req = $conn->prepare("SELECT employee_id, ot_date, total_hours FROM overtime_requests WHERE id = ?");
            $req->bind_param('i', $request_id);
            $req->execute();
            $r = $req->get_result()->fetch_assoc();
            $req->close();

            if ($r) {
                $date_display = date('M d, Y', strtotime($r['ot_date']));
                create_notification($conn, $r['employee_id'], 'ot_' . strtolower($status), "Your OT request for $date_display ({$r['total_hours']}h) has been $status.", 'overtimerequest.php');
            }
        }
        $stmt->close();
    }
}

$has_source = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'source'")->num_rows > 0;
if ($has_source) {
    $result = $conn->query("
        SELECT otr.*, e.name as employee_name, e.employee_code
        FROM overtime_requests otr
        JOIN employee e ON otr.employee_id = e.id
        WHERE (otr.source IS NULL OR otr.source = 'employee_request')
        ORDER BY otr.created_at DESC
    ");
} else {
    $result = $conn->query("
        SELECT otr.*, e.name as employee_name, e.employee_code
        FROM overtime_requests otr
        JOIN employee e ON otr.employee_id = e.id
        ORDER BY otr.created_at DESC
    ");
}
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
    <title>HNIN AKARI NWE · Overtime Approvals</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Overtime Approvals"; $page_subtitle = "Review and manage employee overtime requests."; $page_actions = ($pending_count > 0) ? '<span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-amber-500/20 text-amber-400"><i class="fa-solid fa-clock mr-1"></i> ' . $pending_count . ' pending</span>' : ''; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($requests)): ?>
            <div class="empty-state glass-strong rounded-2xl p-12">
                <svg class="w-24 h-24 mx-auto mb-6 text-zinc-600 dark:text-zinc-700" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="50" cy="50" r="35" stroke="currentColor" stroke-width="2" opacity="0.2"/>
                    <circle cx="50" cy="50" r="28" stroke="currentColor" stroke-width="1.5" opacity="0.1"/>
                    <line x1="50" y1="32" x2="50" y2="50" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity="0.3"/>
                    <line x1="50" y1="50" x2="65" y2="55" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity="0.3"/>
                    <circle cx="50" cy="50" r="4" fill="currentColor" opacity="0.15"/>
                    <path d="M78 32l6-6 10 10" stroke="url(#grad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.6"/>
                    <circle cx="85" cy="25" r="14" stroke="currentColor" stroke-width="2" opacity="0.15"/>
                    <defs><linearGradient id="grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#e879f9"/></linearGradient></defs>
                </svg>
                <h3 class="text-xl font-bold text-white">No overtime requests</h3>
                <p class="text-zinc-400 mt-2 max-w-md mx-auto">Overtime submissions from employees will show up here once they are filed.</p>
            </div>
            <?php else: ?>
            <div class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4">OT Date</th>
                                <th class="px-6 py-4">Time</th>
                                <th class="px-6 py-4">Hours</th>
                                <th class="px-6 py-4">Reason</th>
                                <th class="px-6 py-4">Submitted</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                            <?php foreach ($requests as $req): ?>
                            <tr class="<?php echo $req['status'] == 'Pending' ? 'bg-amber-500/5' : ''; ?> hover:bg-white/[0.02] transition">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white"><?php echo htmlspecialchars($req['employee_name']); ?></div>
                                    <div class="text-xs text-zinc-500"><?php echo htmlspecialchars($req['employee_code']); ?></div>
                                </td>
                                <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($req['ot_date'])); ?></td>
                                <td class="px-6 py-4 font-mono text-sm">
                                    <?php echo date('h:i A', strtotime($req['start_time'])); ?> - <?php echo date('h:i A', strtotime($req['end_time'])); ?>
                                </td>
                                <td class="px-6 py-4 font-semibold"><?php echo $req['total_hours']; ?>h</td>
                                <td class="px-6 py-4 text-zinc-400 max-w-[150px] truncate text-sm" title="<?php echo htmlspecialchars($req['reason']); ?>"><?php echo htmlspecialchars($req['reason']); ?></td>
                                <td class="px-6 py-4 text-xs text-zinc-500"><?php echo date('M d, h:i A', strtotime($req['created_at'])); ?></td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold
                                        <?php echo $req['status'] == 'Approved' ? 'bg-emerald-500/20 text-emerald-400' : ''; ?>
                                        <?php echo $req['status'] == 'Rejected' ? 'bg-red-500/20 text-red-400' : ''; ?>
                                        <?php echo $req['status'] == 'Pending' ? 'bg-amber-500/20 text-amber-400' : ''; ?>
                                    "><?php echo $req['status']; ?></span>
                                </td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <?php if ($req['status'] == 'Pending'): ?>
                                    <form method="POST" class="inline">
                                    <?php echo csrf_field(); ?>
                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                        <button type="submit" name="action" value="approve" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-4 py-2 shadow-sm transition">
                                            <i class="fa-solid fa-check"></i> Approve
                                        </button>
                                        <button type="submit" name="action" value="reject" class="rounded-xl border border-red-500/30 hover:bg-red-500/10 text-red-400 font-semibold text-xs px-4 py-2 transition">
                                            <i class="fa-solid fa-times"></i> Reject
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-xs text-zinc-500">--</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
</body>
</html>
