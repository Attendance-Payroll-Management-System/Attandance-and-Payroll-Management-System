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

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS attendance_corrections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_id INT,
    attendance_date DATE NOT NULL,
    current_check_in TIME,
    current_check_out TIME,
    requested_check_in TIME,
    requested_check_out TIME,
    original_check_in TIME DEFAULT NULL,
    original_check_out TIME DEFAULT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_at DATETIME,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES employee(id) ON DELETE SET NULL,
    INDEX idx_corrections_employee (employee_id),
    INDEX idx_corrections_status (status),
    INDEX idx_corrections_date (attendance_date)
)");

// Add audit columns to existing attendance_corrections table (MySQL 5.7 compatible)
foreach (['original_check_in', 'original_check_out'] as $col) {
    $check = $conn->query("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_corrections' AND COLUMN_NAME = '$col'");
    if ($check && $check->fetch_assoc()['cnt'] == 0) {
        $after = $col === 'original_check_in' ? 'requested_check_out' : 'original_check_in';
        $conn->query("ALTER TABLE attendance_corrections ADD COLUMN $col TIME DEFAULT NULL AFTER $after");
    }
}

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correction_id = (int)($_POST['correction_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($correction_id > 0 && in_array($action, ['approve', 'reject'])) {
        $stmt = $conn->prepare("SELECT * FROM attendance_corrections WHERE id = ?");
        $stmt->bind_param('i', $correction_id);
        $stmt->execute();
        $correction = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($correction) {
            if ($action === 'approve') {
                // Resolve the attendance record: try by ID first, then by employee_id + date
                $att_id = $correction['attendance_id'] ? (int)$correction['attendance_id'] : null;
                $att_row = null;

                if ($att_id) {
                    $att_q = $conn->prepare("SELECT id, check_in, check_out FROM attendance WHERE id = ?");
                    $att_q->bind_param('i', $att_id);
                    $att_q->execute();
                    $att_row = $att_q->get_result()->fetch_assoc();
                    $att_q->close();
                }

                // Fallback: look up by employee_id + date
                if (!$att_row) {
                    $att_q = $conn->prepare("SELECT id, check_in, check_out FROM attendance WHERE employee_id = ? AND attendance_date = ?");
                    $att_q->bind_param('is', $correction['employee_id'], $correction['attendance_date']);
                    $att_q->execute();
                    $att_row = $att_q->get_result()->fetch_assoc();
                    $att_q->close();
                    if ($att_row) $att_id = (int)$att_row['id'];
                }

                // If still no record, create one
                if (!$att_row) {
                    $ins = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, check_in, check_out, is_manual, auto_calculated) VALUES (?, ?, ?, ?, 1, 1)");
                    $ins->bind_param('isss', $correction['employee_id'], $correction['attendance_date'], $correction['requested_check_in'], $correction['requested_check_out']);
                    $ins->execute();
                    $att_id = (int)$conn->insert_id;
                    $ins->close();
                    $att_row = ['id' => $att_id, 'check_in' => $correction['requested_check_in'], 'check_out' => $correction['requested_check_out']];
                }

                // Determine final values: use requested if provided, otherwise keep existing
                $final_check_in  = $correction['requested_check_in']  ?: $att_row['check_in'];
                $final_check_out = $correction['requested_check_out'] ?: $att_row['check_out'];

                // Store original values in the correction record for audit trail
                $audit = $conn->prepare("UPDATE attendance_corrections 
                    SET original_check_in = ?, original_check_out = ? 
                    WHERE id = ?");
                $audit->bind_param('ssi', $att_row['check_in'], $att_row['check_out'], $correction_id);
                $audit->execute();
                $audit->close();

                // Update the attendance record with corrected times
                $update = $conn->prepare("UPDATE attendance SET 
                    check_in = ?, check_out = ?, is_manual = 1 
                    WHERE id = ?");
                $update->bind_param('ssi', $final_check_in, $final_check_out, $att_id);

                if ($update->execute()) {
                    $update->close();

                    // Recalculate total working hours
                    $total_hours = 0;
                    if ($final_check_in && $final_check_out) {
                        $total_hours = round((strtotime($final_check_out) - strtotime($final_check_in)) / 3600, 2);
                        if ($total_hours < 0) $total_hours = 0;
                    }

                    // Recalculate all attendance fields (status, is_late, total_working_hours, deductions)
                    recalculate_attendance_after_checkout($conn, $att_id, $correction['employee_id'], $correction['attendance_date'], $final_check_in, $final_check_out, $total_hours);

                    // Mark correction as approved
                    $stmt = $conn->prepare("UPDATE attendance_corrections SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                    $stmt->bind_param('ii', $_SESSION['admin_id'], $correction_id);
                    $stmt->execute();
                    $stmt->close();

                    create_notification($conn, $correction['employee_id'], 'attendance_correction', 'Your attendance correction for ' . $correction['attendance_date'] . ' has been approved.', 'attendance.php');
                    log_activity($conn, $_SESSION['admin_id'], 'approve_correction', "Attendance correction #$correction_id approved. Check-in: " . ($final_check_in ?? 'N/A') . ", Check-out: " . ($final_check_out ?? 'N/A') . ", Hours: $total_hours");

                    $message = 'Correction request approved successfully. Attendance record updated.';
                    $message_type = 'success';
                } else {
                    $message = 'Error updating attendance: ' . $update->error;
                    $message_type = 'error';
                    $update->close();
                }
            } elseif ($action === 'reject') {
                $rejection_reason = trim($_POST['rejection_reason'] ?? '');
                $stmt = $conn->prepare("UPDATE attendance_corrections SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?");
                $stmt->bind_param('isi', $_SESSION['admin_id'], $rejection_reason, $correction_id);
                $stmt->execute();
                $stmt->close();

                create_notification($conn, $correction['employee_id'], 'attendance_correction', 'Your attendance correction for ' . $correction['attendance_date'] . ' was rejected.' . ($rejection_reason ? " Reason: $rejection_reason" : ''), 'attendance.php');
                log_activity($conn, $_SESSION['admin_id'], 'reject_correction', "Attendance correction #$correction_id rejected");

                $message = 'Correction request rejected.';
                $message_type = 'success';
            }
        }
    }
}

// Fetch corrections
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$where = $filter_status ? "ac.status = '" . $conn->real_escape_string($filter_status) . "'" : '1=1';

$corrections = $conn->query("SELECT ac.*, e.name as employee_name, e.employee_code, d.department_name 
    FROM attendance_corrections ac 
    JOIN employee e ON ac.employee_id = e.id 
    LEFT JOIN departments d ON e.department_id = d.id 
    WHERE $where 
    ORDER BY ac.created_at DESC")->fetch_all(MYSQLI_ASSOC);

$counts = [];
$res = $conn->query("SELECT status, COUNT(*) as cnt FROM attendance_corrections GROUP BY status");
while ($row = $res->fetch_assoc()) {
    $counts[$row['status']] = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance Corrections</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ 
    selectedId: 0, 
    showRejectModal: false,
    rejectReason: ''
}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php include "../includes/topbar.php"; ?>
        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter">

            <?php if ($message): ?>
            <div class="flex items-center gap-3 p-4 rounded-xl border text-sm <?php echo $message_type === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 text-white flex items-center justify-center text-xl shadow-lg shadow-amber-500/25">
                    <i class="fa-solid fa-pen-to-square"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-extrabold text-white tracking-tight">Attendance Corrections</h1>
                    <p class="text-sm text-zinc-400">Review and approve employee attendance correction requests</p>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="flex gap-2">
                <a href="?status=pending" class="px-5 py-2.5 rounded-xl text-sm font-semibold transition-all <?php echo $filter_status === 'pending' ? 'bg-amber-500/20 text-amber-400 border border-amber-500/20' : 'bg-white/[0.04] text-zinc-400 hover:text-white hover:bg-white/[0.06]'; ?>">
                    Pending <?php if (($counts['pending'] ?? 0) > 0): ?><span class="ml-1.5 px-1.5 py-0.5 bg-amber-500/20 text-amber-400 rounded-full text-[10px]"><?php echo $counts['pending']; ?></span><?php endif; ?>
                </a>
                <a href="?status=approved" class="px-5 py-2.5 rounded-xl text-sm font-semibold transition-all <?php echo $filter_status === 'approved' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/20' : 'bg-white/[0.04] text-zinc-400 hover:text-white hover:bg-white/[0.06]'; ?>">
                    Approved <span class="ml-1.5 text-zinc-500 text-xs"><?php echo $counts['approved'] ?? 0; ?></span>
                </a>
                <a href="?status=rejected" class="px-5 py-2.5 rounded-xl text-sm font-semibold transition-all <?php echo $filter_status === 'rejected' ? 'bg-rose-500/20 text-rose-400 border border-rose-500/20' : 'bg-white/[0.04] text-zinc-400 hover:text-white hover:bg-white/[0.06]'; ?>">
                    Rejected <span class="ml-1.5 text-zinc-500 text-xs"><?php echo $counts['rejected'] ?? 0; ?></span>
                </a>
                <a href="?status=" class="px-5 py-2.5 rounded-xl text-sm font-semibold transition-all <?php echo !$filter_status ? 'bg-white/[0.08] text-white border border-white/10' : 'bg-white/[0.04] text-zinc-400 hover:text-white hover:bg-white/[0.06]'; ?>">
                    All <span class="ml-1.5 text-zinc-500 text-xs"><?php echo array_sum($counts); ?></span>
                </a>
            </div>

            <!-- Corrections List -->
            <?php if (empty($corrections)): ?>
            <div class="glass-strong rounded-2xl p-12 text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-white/[0.04] flex items-center justify-center">
                    <i class="fa-solid fa-check-circle text-2xl text-zinc-600"></i>
                </div>
                <p class="text-zinc-400 font-medium">No correction requests found.</p>
                <p class="text-xs text-zinc-500 mt-1">All caught up!</p>
            </div>
            <?php else: foreach ($corrections as $c): ?>
            <div class="glass-strong rounded-2xl p-6 <?php echo $c['status'] === 'pending' ? 'border-l-4 border-l-amber-500' : ($c['status'] === 'approved' ? 'border-l-4 border-l-emerald-500' : 'border-l-4 border-l-rose-500'); ?>">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-sky-500/20 to-blue-500/20 text-sky-400 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-white"><?php echo htmlspecialchars($c['employee_name']); ?></h3>
                            <p class="text-xs text-zinc-400"><?php echo htmlspecialchars($c['employee_code']); ?> · <?php echo htmlspecialchars($c['department_name'] ?? 'N/A'); ?></p>
                            <p class="text-xs text-zinc-500 mt-1">Submitted: <?php echo date('M d, Y h:i A', strtotime($c['created_at'])); ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if ($c['status'] === 'approved'): ?>
                            <span class="px-3 py-1 bg-emerald-500/20 text-emerald-400 rounded-full text-xs font-semibold">Approved</span>
                        <?php elseif ($c['status'] === 'rejected'): ?>
                            <span class="px-3 py-1 bg-rose-500/20 text-rose-400 rounded-full text-xs font-semibold">Rejected</span>
                        <?php else: ?>
                            <span class="px-3 py-1 bg-amber-500/20 text-amber-400 rounded-full text-xs font-semibold">Pending</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <p class="text-xs text-zinc-500 mb-1">Date</p>
                        <p class="text-sm font-semibold text-white"><?php echo date('M d, Y', strtotime($c['attendance_date'])); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-500 mb-1">Current Times</p>
                        <p class="text-sm text-zinc-300">In: <?php echo $c['current_check_in'] ? date('h:i A', strtotime($c['current_check_in'])) : '—'; ?> | Out: <?php echo $c['current_check_out'] ? date('h:i A', strtotime($c['current_check_out'])) : '—'; ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-500 mb-1">Requested Times</p>
                        <p class="text-sm font-semibold text-emerald-400">In: <?php echo $c['requested_check_in'] ? date('h:i A', strtotime($c['requested_check_in'])) : '—'; ?> | Out: <?php echo $c['requested_check_out'] ? date('h:i A', strtotime($c['requested_check_out'])) : '—'; ?></p>
                    </div>
                </div>

                <?php if ($c['status'] === 'approved' && ($c['original_check_in'] || $c['original_check_out'])): ?>
                <div class="mt-2 p-3 bg-emerald-500/5 border border-emerald-500/10 rounded-xl">
                    <p class="text-xs text-emerald-400 font-semibold mb-1"><i class="fa-solid fa-check-circle mr-1"></i> Audit Trail — Values Applied</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                        <div>
                            <span class="text-zinc-500">Original:</span>
                            <span class="text-zinc-300 ml-1">In: <?php echo $c['original_check_in'] ? date('h:i A', strtotime($c['original_check_in'])) : '—'; ?> | Out: <?php echo $c['original_check_out'] ? date('h:i A', strtotime($c['original_check_out'])) : '—'; ?></span>
                        </div>
                        <div>
                            <span class="text-zinc-500">Corrected:</span>
                            <span class="text-emerald-400 font-semibold ml-1">In: <?php echo $c['requested_check_in'] ? date('h:i A', strtotime($c['requested_check_in'])) : '—'; ?> | Out: <?php echo $c['requested_check_out'] ? date('h:i A', strtotime($c['requested_check_out'])) : '—'; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mt-2">
                    <p class="text-xs text-zinc-500 mb-1">Reason</p>
                    <p class="text-sm text-zinc-300 bg-white/[0.03] rounded-xl p-3"><?php echo htmlspecialchars($c['reason']); ?></p>
                </div>

                <?php if ($c['rejection_reason']): ?>
                <div class="mt-2">
                    <p class="text-xs text-rose-500 mb-1">Rejection Reason</p>
                    <p class="text-sm text-rose-300 bg-rose-500/10 rounded-xl p-3"><?php echo htmlspecialchars($c['rejection_reason']); ?></p>
                </div>
                <?php endif; ?>

                <?php if ($c['status'] === 'pending'): ?>
                <div class="mt-4 flex items-center gap-3 pt-4 border-t border-white/[0.06]">
                    <form method="POST" class="inline">
                        <input type="hidden" name="correction_id" value="<?php echo $c['id']; ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl text-sm font-semibold transition-all">
                            <i class="fa-solid fa-check mr-1"></i> Approve
                        </button>
                    </form>
                    <button @click="selectedId=<?php echo $c['id']; ?>; showRejectModal=true; rejectReason=''" class="px-5 py-2 bg-rose-500/10 border border-rose-500/20 text-rose-400 hover:bg-rose-500/20 rounded-xl text-sm font-semibold transition-all">
                        <i class="fa-solid fa-xmark mr-1"></i> Reject
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>

            <!-- Reject Modal -->
            <div x-show="showRejectModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.outside="showRejectModal = false">
                <div class="bg-[#1E293B] rounded-2xl border border-white/[0.06] shadow-2xl w-full max-w-md">
                    <div class="px-6 py-4 border-b border-white/[0.06]">
                        <h3 class="text-lg font-bold text-white">Reject Correction Request</h3>
                    </div>
                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="correction_id" x-model="selectedId">
                        <input type="hidden" name="action" value="reject">
                        <div>
                            <label class="block text-sm font-semibold text-zinc-300 mb-1">Reason for rejection</label>
                            <textarea name="rejection_reason" x-model="rejectReason" rows="3" class="w-full bg-white/[0.06] border border-white/10 text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-rose-500/30" placeholder="Enter reason..."></textarea>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showRejectModal = false" class="px-5 py-2.5 bg-white/[0.06] text-zinc-300 rounded-xl text-sm font-semibold hover:bg-white/[0.10] transition-all">Cancel</button>
                            <button type="submit" class="px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-sm font-semibold transition-all">Reject</button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
