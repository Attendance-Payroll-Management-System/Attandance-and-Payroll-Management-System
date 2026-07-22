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
$currency = get_currency($conn);

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];

// Role gate: only approver-role employees can access this page
$role_stmt = $conn->prepare("SELECT role FROM employee WHERE id = ?");
$role_stmt->bind_param('i', $employee_id);
$role_stmt->execute();
$role_row = $role_stmt->get_result()->fetch_assoc();
$role_stmt->close();
$user_role = strtolower(trim($role_row['role'] ?? ''));
$approver_roles = ['admin', 'manager', 'supervisor', 'officer'];
if (!in_array($user_role, $approver_roles) && $employee_id != 1) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$message_type = '';

$has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;
$has_ot_rate = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_rate'")->num_rows > 0;
$has_ot_pay = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_pay'")->num_rows > 0;
$has_approver_id = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'approver_id'")->num_rows > 0;
$has_remarks = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'remarks'")->num_rows > 0;

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) {
        $message = "Invalid request.";
        $message_type = "error";
    } else {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $remarks = trim($_POST['remarks'] ?? '');

        $status = null;
        if ($action === 'approve') $status = 'Approved';
        elseif ($action === 'reject') $status = 'Rejected';

        if ($status && $request_id > 0) {
            $check = $conn->prepare("SELECT * FROM overtime_requests WHERE id = ? AND approver_id = ? AND status = 'Pending'");
            $check->bind_param('ii', $request_id, $employee_id);
            $check->execute();
            $old_row = $check->get_result()->fetch_assoc();
            $check->close();

            if ($old_row) {
                if ($status === 'Approved' && $has_ot_type) {
                    $ot_type = detect_overtime_type($conn, $old_row['ot_date']);
                    $ot_rate = get_overtime_rate_for_type($ot_type);
                    $ot_pay = calculate_overtime_pay_for_request($conn, $old_row['employee_id'], $ot_type, (float)$old_row['total_hours']);

                    $set_clauses = ['status = ?', 'ot_type = ?'];
                    $params = [$status, $ot_type];
                    $types = 'ss';
                    if ($has_ot_rate) { $set_clauses[] = 'ot_rate = ?'; $params[] = $ot_rate; $types .= 'd'; }
                    if ($has_ot_pay) { $set_clauses[] = 'ot_pay = ?'; $params[] = $ot_pay; $types .= 'd'; }
                    if ($has_remarks) { $set_clauses[] = 'remarks = ?'; $params[] = $remarks; $types .= 's'; }
                    $set_clauses[] = 'approved_by = ?'; $params[] = $employee_id; $types .= 'i';
                    $set_clauses[] = 'approved_at = NOW()';
                    $params[] = $request_id; $types .= 'i';
                    $stmt = $conn->prepare("UPDATE overtime_requests SET " . implode(', ', $set_clauses) . " WHERE id = ?");
                    $stmt->bind_param($types, ...$params);
                } else {
                    if ($has_remarks) {
                        $stmt = $conn->prepare("UPDATE overtime_requests SET status = ?, remarks = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                        $stmt->bind_param('ssii', $status, $remarks, $employee_id, $request_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE overtime_requests SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                        $stmt->bind_param('sii', $status, $employee_id, $request_id);
                    }
                }

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
                        create_notification($conn, $r['employee_id'], 'ot_' . strtolower($status), "Your OT request for $date_display ({$r['total_hours']}h) has been $status by $employee_name.", 'overtimerequest.php');
                    }

                    log_overtime_action($conn, $request_id, $status === 'Approved' ? 'approved' : 'rejected', $employee_id, 'employee', $old_row, [
                        'status' => $status,
                        'approved_by' => $employee_id,
                        'approved_at' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $message = "Error updating overtime request.";
                    $message_type = "error";
                }
                $stmt->close();
            } else {
                $message = "Request not found or not assigned to you.";
                $message_type = "error";
            }
        }
    }
}

// Fetch pending requests assigned to this approver
$ot_cols = '';
if ($has_ot_type) $ot_cols .= ', otr.ot_type';
if ($has_ot_rate) $ot_cols .= ', otr.ot_rate';
if ($has_ot_pay) $ot_cols .= ', otr.ot_pay';
if ($has_remarks) $ot_cols .= ', otr.remarks';

$pending_stmt = $conn->prepare("
    SELECT otr.*$ot_cols, e.name as employee_name, e.employee_code, d.department_name
    FROM overtime_requests otr
    JOIN employee e ON otr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE otr.approver_id = ? AND otr.status = 'Pending'
    ORDER BY otr.created_at DESC
");
$pending_stmt->bind_param('i', $employee_id);
$pending_stmt->execute();
$pending_requests = $pending_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pending_stmt->close();

// Fetch recent decisions (history)
$history_stmt = $conn->prepare("
    SELECT otr.*$ot_cols, e.name as employee_name, e.employee_code, d.department_name
    FROM overtime_requests otr
    JOIN employee e ON otr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE otr.approved_by = ? AND otr.status != 'Pending'
    ORDER BY otr.approved_at DESC
    LIMIT 50
");
$history_stmt->bind_param('i', $employee_id);
$history_stmt->execute();
$history = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$history_stmt->close();

$pending_count = count($pending_requests);
$pending_hours = array_sum(array_column($pending_requests, 'total_hours'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE - OT Approvals</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php
        $page_title = "OT Approvals";
        $page_subtitle = "Review and manage overtime requests assigned to you.";
        $page_actions = $pending_count > 0
            ? '<span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-amber-500/20 text-amber-400"><i class="fa-solid fa-clock mr-1"></i> ' . $pending_count . ' pending (' . number_format($pending_hours, 1) . 'h)</span>'
            : '<span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-emerald-500/20 text-emerald-400"><i class="fa-solid fa-check-circle mr-1"></i> All caught up</span>';
        include "../includes/topbar.php";
        ?>

        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full">
            <?php if ($message): ?>
                <div class="px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Pending Requests</span>
                    <p class="text-lg font-bold text-amber-400"><?php echo $pending_count; ?></p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Pending Hours</span>
                    <p class="text-lg font-bold text-white"><?php echo number_format($pending_hours, 1); ?>h</p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">You Approved</span>
                    <p class="text-lg font-bold text-emerald-400"><?php echo count(array_filter($history, fn($h) => $h['status'] === 'Approved')); ?></p>
                </div>
                <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">You Rejected</span>
                    <p class="text-lg font-bold text-rose-400"><?php echo count(array_filter($history, fn($h) => $h['status'] === 'Rejected')); ?></p>
                </div>
            </div>

            <?php if (empty($pending_requests)): ?>
            <div class="glass-strong rounded-2xl p-12 text-center">
                <div class="w-20 h-20 rounded-2xl bg-emerald-500/10 flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-check-double text-3xl text-emerald-500/40"></i>
                </div>
                <h3 class="text-lg font-bold text-white">No pending requests</h3>
                <p class="text-zinc-400 text-sm mt-2 max-w-md mx-auto">All overtime requests assigned to you have been reviewed. New requests will appear here automatically.</p>
            </div>
            <?php else: ?>
            <div class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-5 border-b border-white/[0.06] flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500/20 to-orange-500/10 flex items-center justify-center">
                        <i class="fa-solid fa-clock text-amber-500"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white">Pending Requests</h3>
                        <p class="text-xs text-zinc-500"><?php echo $pending_count; ?> request<?php echo $pending_count !== 1 ? 's' : ''; ?> awaiting your review</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">Employee</th>
                                <th class="px-6 py-4">Department</th>
                                <th class="px-6 py-4">OT Date</th>
                                <th class="px-6 py-4">Time</th>
                                <th class="px-6 py-4">Hours</th>
                                <th class="px-6 py-4">Type</th>
                                <th class="px-6 py-4">Est. Pay</th>
                                <th class="px-6 py-4">Reason</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                            <?php foreach ($pending_requests as $req): ?>
                            <?php $ot_type_display = $has_ot_type && $req['ot_type'] ? get_overtime_type_badge($req['ot_type']) : '<span class="text-xs text-zinc-500">-</span>'; ?>
                            <tr class="bg-amber-500/5 hover:bg-amber-500/10 transition">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white"><?php echo htmlspecialchars($req['employee_name']); ?></div>
                                    <div class="text-xs text-zinc-500"><?php echo htmlspecialchars($req['employee_code']); ?></div>
                                </td>
                                <td class="px-6 py-4 text-zinc-400 text-sm"><?php echo htmlspecialchars($req['department_name'] ?? '-'); ?></td>
                                <td class="px-6 py-4 font-medium text-white"><?php echo date('M d, Y', strtotime($req['ot_date'])); ?></td>
                                <td class="px-6 py-4 font-mono text-sm"><?php echo date('h:i A', strtotime($req['start_time'])); ?> - <?php echo date('h:i A', strtotime($req['end_time'])); ?></td>
                                <td class="px-6 py-4 font-bold text-white"><?php echo $req['total_hours']; ?>h</td>
                                <td class="px-6 py-4"><?php echo $ot_type_display; ?></td>
                                <td class="px-6 py-4 font-mono text-sm"><?php if ($has_ot_pay && isset($req['ot_pay']) && $req['ot_pay'] > 0): ?><span class="text-emerald-400 font-semibold"><?php echo $currency; ?> <?php echo number_format($req['ot_pay'], 2); ?></span><?php else: ?><span class="text-zinc-500">-</span><?php endif; ?></td>
                                <td class="px-6 py-4 text-zinc-400 max-w-[180px] truncate text-sm" title="<?php echo htmlspecialchars($req['reason']); ?>"><?php echo htmlspecialchars($req['reason']); ?></td>
                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <form method="POST" class="inline-flex flex-col gap-2 items-end">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                        <?php if ($has_remarks): ?>
                                        <input type="text" name="remarks" placeholder="Remarks (optional)" class="text-xs px-3 py-1.5 rounded-lg bg-white/[0.06] border border-white/10 text-white w-48 focus:outline-blue-500">
                                        <?php endif; ?>
                                        <div class="flex gap-2">
                                            <button type="submit" name="action" value="approve" class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs px-4 py-2 shadow-sm transition flex items-center gap-1"><i class="fa-solid fa-check"></i> Approve</button>
                                            <button type="submit" name="action" value="reject" class="rounded-xl border border-red-500/30 hover:bg-red-500/10 text-red-400 font-semibold text-xs px-4 py-2 transition flex items-center gap-1"><i class="fa-solid fa-times"></i> Reject</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($history)): ?>
            <div class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="p-5 border-b border-white/[0.06] flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500/20 to-cyan-500/10 flex items-center justify-center">
                        <i class="fa-solid fa-clock-rotate-left text-sky-500"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-white">Recent Decisions</h3>
                        <p class="text-xs text-zinc-500"><?php echo count($history); ?> recent decision<?php echo count($history) !== 1 ? 's' : ''; ?></p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-3">Employee</th>
                                <th class="px-6 py-3">OT Date</th>
                                <th class="px-6 py-3">Hours</th>
                                <th class="px-6 py-3">Type</th>
                                <th class="px-6 py-3">Pay</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3">Remarks</th>
                                <th class="px-6 py-3">Decision Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                            <?php foreach ($history as $h): ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="px-6 py-3">
                                    <div class="font-medium text-white text-sm"><?php echo htmlspecialchars($h['employee_name']); ?></div>
                                    <div class="text-[10px] text-zinc-500"><?php echo htmlspecialchars($h['employee_code']); ?></div>
                                </td>
                                <td class="px-6 py-3 text-sm"><?php echo date('M d, Y', strtotime($h['ot_date'])); ?></td>
                                <td class="px-6 py-3 font-semibold text-sm"><?php echo $h['total_hours']; ?>h</td>
                                <td class="px-6 py-3"><?php if ($has_ot_type && $h['ot_type']): ?><?php echo get_overtime_type_badge($h['ot_type']); ?><?php else: ?><span class="text-xs text-zinc-500">-</span><?php endif; ?></td>
                                <td class="px-6 py-3 font-mono text-sm"><?php if ($has_ot_pay && isset($h['ot_pay']) && $h['ot_pay'] > 0): ?><span class="text-emerald-400 font-semibold"><?php echo $currency; ?> <?php echo number_format($h['ot_pay'], 2); ?></span><?php else: ?><span class="text-zinc-500">-</span><?php endif; ?></td>
                                <td class="px-6 py-3"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold <?php echo $h['status'] == 'Approved' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400'; ?>"><?php echo $h['status']; ?></span></td>
                                <td class="px-6 py-3 text-sm text-zinc-400 max-w-[200px] truncate" title="<?php echo htmlspecialchars($h['remarks'] ?? ''); ?>"><?php echo htmlspecialchars($h['remarks'] ?? '-'); ?></td>
                                <td class="px-6 py-3 text-xs text-zinc-500"><?php echo $h['approved_at'] ? date('M d, h:i A', strtotime($h['approved_at'])) : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>
