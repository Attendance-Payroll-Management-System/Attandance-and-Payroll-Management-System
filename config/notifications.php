<?php
function create_notification($conn, $employee_id, $type, $message, $link = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (employee_id, type, message, link) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $employee_id, $type, $message, $link);
    $stmt->execute();
    $stmt->close();
}

function get_notifications($conn, $employee_id = null, $limit = 10) {
    if ($employee_id) {
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE employee_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param('ii', $employee_id, $limit);
    } else {
        $stmt = $conn->prepare("SELECT n.*, e.name as emp_name FROM notifications n LEFT JOIN employee e ON n.employee_id = e.id WHERE n.employee_id IS NULL ORDER BY n.created_at DESC LIMIT ?");
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function get_unread_count($conn, $employee_id = null) {
    if ($employee_id) {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE employee_id = ? AND is_read = 0");
        $stmt->bind_param('i', $employee_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE employee_id IS NULL AND is_read = 0");
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['cnt'];
}

function mark_notifications_read($conn, $employee_id = null) {
    if ($employee_id) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE employee_id = ?");
        $stmt->bind_param('i', $employee_id);
    } else {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE employee_id IS NULL");
    }
    $stmt->execute();
    $stmt->close();
}

function mark_notification_read($conn, int $notification_id) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param('i', $notification_id);
    $stmt->execute();
    $stmt->close();
}

// ─── Notification Icon Helper ──────────────────────────────────
function get_notification_icon(string $type): string {
    return match($type) {
        'leave_request' => 'fa-plane-departure',
        'leave_approved' => 'fa-plane-departure',
        'leave_rejected' => 'fa-plane-departure',
        'ot_request' => 'fa-clock',
        'ot_approved' => 'fa-clock',
        'ot_rejected' => 'fa-clock',
        'ot_assigned' => 'fa-clock',
        'payroll_generated' => 'fa-file-invoice-dollar',
        'payroll_paid' => 'fa-sack-dollar',
        'attendance_correction' => 'fa-pen',
        default => 'fa-bell',
    };
}

function get_notification_color(string $type): string {
    return match($type) {
        'leave_request' => 'text-blue-500',
        'leave_approved' => 'text-emerald-500',
        'leave_rejected' => 'text-rose-500',
        'ot_request' => 'text-purple-500',
        'ot_approved' => 'text-emerald-500',
        'ot_rejected' => 'text-rose-500',
        'ot_assigned' => 'text-amber-500',
        'payroll_generated' => 'text-indigo-500',
        'payroll_paid' => 'text-emerald-500',
        'attendance_correction' => 'text-cyan-500',
        default => 'text-slate-500',
    };
}

function get_notification_bg_color(string $type): string {
    return match($type) {
        'leave_request' => 'bg-blue-500/10',
        'leave_approved' => 'bg-emerald-500/10',
        'leave_rejected' => 'bg-rose-500/10',
        'ot_request' => 'bg-purple-500/10',
        'ot_approved' => 'bg-emerald-500/10',
        'ot_rejected' => 'bg-rose-500/10',
        'ot_assigned' => 'bg-amber-500/10',
        'payroll_generated' => 'bg-indigo-500/10',
        'payroll_paid' => 'bg-emerald-500/10',
        'attendance_correction' => 'bg-cyan-500/10',
        default => 'bg-slate-500/10',
    };
}

// ─── Payroll Notification Helpers ──────────────────────────────
function create_payroll_notification($conn, int $employee_id, string $action, string $month_name, int $year, float $amount) {
    $link = '../employee/payroll.php';
    
    $message = match($action) {
        'generated' => "Your {$month_name} {$year} payroll has been generated. Net salary: \$" . number_format($amount, 2),
        'paid' => "Your {$month_name} {$year} salary of \$" . number_format($amount, 2) . " has been paid.",
        default => "Payroll update for {$month_name} {$year}.",
    };
    
    $type = "payroll_" . $action;
    create_notification($conn, $employee_id, $type, $message, $link);
}

// ─── Leave Notification Helpers ────────────────────────────────
function create_leave_request_notification($conn, int $employee_id, string $emp_name, string $leave_type, string $start_date, string $end_date) {
    // Notify admin (employee_id = NULL for admin notifications)
    $msg = "{$emp_name} requested {$leave_type} ({$start_date} to {$end_date})";
    create_notification($conn, null, 'leave_request', $msg, '../admin/leaveApproval.php');
}

function create_leave_status_notification($conn, int $employee_id, string $leave_type, string $start_date, string $end_date, string $status) {
    $msg = "Your {$leave_type} request ({$start_date} to {$end_date}) has been {$status}.";
    $link = 'leaverequest.php';
    create_notification($conn, $employee_id, 'leave_' . strtolower($status), $msg, $link);
}

// ─── Overtime Notification Helpers ─────────────────────────────
function create_overtime_request_notification($conn, int $employee_id, string $emp_name, string $ot_date, float $hours) {
    $msg = "{$emp_name} requested OT on {$ot_date} ({$hours}h). Please review.";
    create_notification($conn, null, 'ot_request', $msg, '../admin/overtimeApproval.php');
}

function create_overtime_status_notification($conn, int $employee_id, string $ot_date, float $hours, string $status) {
    $msg = "Your OT request for {$ot_date} ({$hours}h) has been {$status}.";
    $link = 'overtimerequest.php';
    create_notification($conn, $employee_id, 'ot_' . strtolower($status), $msg, $link);
}

