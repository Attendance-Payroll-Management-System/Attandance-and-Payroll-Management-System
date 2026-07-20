<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
require_once "../config/notifications.php";
if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }
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
$has_approver_id = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'approver_id'")->num_rows > 0;
$has_approver_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'approver_type'")->num_rows > 0;
$admins = $conn->query("SELECT id, name FROM employee WHERE (role IN ('Admin','admin','Manager','manager','Supervisor','supervisor','officer') OR id = 1) AND status = 'active' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Handle Submit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ot'])) {
    if (!validate_csrf_token()) { $message = "Invalid request."; $message_type = "error"; } else {
        $ot_date = $_POST['ot_date'] ?? ''; $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? ''; $reason = trim($_POST['reason'] ?? '');
        $approver_id = (int)($_POST['approver_id'] ?? 0); $approver_type = $_POST['approver_type'] ?? 'admin';
        if ($is_inactive) { $message = "Your account is inactive."; $message_type = "error"; } else {
            $errors = validate_overtime_request_rules($conn, $employee_id, $ot_date, $start_time, $end_time, $reason);
            if ($approver_id <= 0) { $errors[] = 'Please select an approver.'; }
            if (!empty($errors)) { $message = implode(' ', $errors); $message_type = "error"; } else {
                $start_ts = strtotime($start_time); $end_ts = strtotime($end_time);
                $total_hours = round(($end_ts - $start_ts) / 3600, 2);
                $ot_type = detect_overtime_type($conn, $ot_date);
                $ot_rate = get_overtime_rate_for_type($ot_type);
                $ot_pay = calculate_overtime_pay_for_request($conn, $employee_id, $ot_type, $total_hours);
                $cols = ['employee_id', 'ot_date', 'start_time', 'end_time', 'total_hours', 'reason', 'status'];
                $vals = [$employee_id, $ot_date, $start_time, $end_time, $total_hours, $reason, 'Pending'];
                $types = 'isssds';
                if ($has_source) { $cols[] = 'source'; $vals[] = 'employee_request'; $types .= 's'; }
                if ($has_request_type) { $cols[] = 'request_type'; $vals[] = 'employee_request'; $types .= 's'; }
                if ($has_ot_type) { $cols[] = 'ot_type'; $vals[] = $ot_type; $types .= 's'; $cols[] = 'ot_rate'; $vals[] = $ot_rate; $types .= 'd'; }
                if ($has_ot_pay) { $cols[] = 'ot_pay'; $vals[] = $ot_pay; $types .= 'd'; }
                if ($has_approver_id) { $cols[] = 'approver_id'; $vals[] = $approver_id; $types .= 'i'; }
                if ($has_approver_type) { $cols[] = 'approver_type'; $vals[] = $approver_type; $types .= 's'; }
                $ph = implode(', ', array_fill(0, count($cols), '?'));
                $stmt = $conn->prepare("INSERT INTO overtime_requests (" . implode(', ', $cols) . ") VALUES ($ph)");
                $stmt->bind_param($types, ...$vals);
                if ($stmt->execute()) {
                    $nid = $stmt->insert_id; $stmt->close();
                    log_overtime_action($conn, $nid, 'created', $employee_id, 'employee');
                    if ($approver_id > 0) create_notification($conn, $approver_id, 'ot_request', "$employee_name requested OT on $ot_date (" . number_format($total_hours, 1) . "h). Please review.", '../admin/overtimeApproval.php');
                    else create_notification($conn, null, 'ot_request', "$employee_name requested OT on $ot_date (" . number_format($total_hours, 1) . "h)", '../admin/overtimeApproval.php');
                    $_SESSION['ot_success'] = "Overtime request submitted. Status: Pending";
                    header('Location: overtimerequest.php'); exit;
                } else { $message = "Error submitting request."; $message_type = "error"; }
                if (isset($stmt)) $stmt->close();
            }
        }
    }
}
// Handle Cancel
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_ot'])) {
    if (!validate_csrf_token()) { $message = "Invalid request."; $message_type = "error"; } else {
        $rid = (int)($_POST['request_id'] ?? 0);
        if ($rid > 0) {
            $stmt = $conn->prepare("UPDATE overtime_requests SET status = 'Cancelled' WHERE id = ? AND employee_id = ? AND status = 'Pending'");
            $stmt->bind_param('ii', $rid, $employee_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) { log_overtime_action($conn, $rid, 'cancelled', $employee_id, 'employee'); $_SESSION['ot_success'] = "Request cancelled."; header('Location: overtimerequest.php'); exit; }
            $stmt->close(); $message = "Cannot cancel."; $message_type = "error";
        }
    }
}
// Handle Assignment Response
if ($has_source && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['respond_ot'])) {
    if (!validate_csrf_token()) { $message = "Invalid request."; $message_type = "error"; } else {
        $rid = $_POST['request_id'] ?? 0; $resp = $_POST['response'] ?? '';
        if ($rid > 0 && in_array($resp, ['accepted', 'rejected'])) {
            $ns = $resp === 'accepted' ? 'Approved' : 'Rejected';
            $stmt = $conn->prepare("UPDATE overtime_requests SET status = ? WHERE id = ? AND employee_id = ? AND source = 'admin_assigned'");
            $stmt->bind_param('sii', $ns, $rid, $employee_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                create_notification($conn, null, 'ot_response', "$employee_name $resp OT assignment.", 'overtimeApproval.php');
                if ($ns === 'Approved' && $has_ot_type) {
                    $r = $conn->prepare("SELECT ot_date, total_hours FROM overtime_requests WHERE id = ?");
                    $r->bind_param('i', $rid); $r->execute(); $row = $r->get_result()->fetch_assoc(); $r->close();
                    if ($row) { $t = detect_overtime_type($conn, $row['ot_date']); $rt = get_overtime_rate_for_type($t); $py = calculate_overtime_pay_for_request($conn, $employee_id, $t, (float)$row['total_hours']);
                        $u = $conn->prepare("UPDATE overtime_requests SET ot_type=?, ot_rate=?, ot_pay=? WHERE id=?"); $u->bind_param('sddi', $t, $rt, $py, $rid); $u->execute(); $u->close(); }
                }
                log_overtime_action($conn, $rid, $resp === 'accepted' ? 'approved' : 'rejected', $employee_id, 'employee');
                header('Location: overtimerequest.php'); exit;
            } else { $message = "Error updating assignment."; $message_type = "error"; }
            $stmt->close();
        }
    }
}

$notifications = get_notifications($conn, $employee_id, 5);
$ot_q = $conn->prepare("SELECT otr.*, e.name as employee_name, a.name as approver_name, ab.name as assigned_by_emp_name FROM overtime_requests otr JOIN employee e ON otr.employee_id=e.id LEFT JOIN employee a ON otr.approver_id=a.id LEFT JOIN employee ab ON otr.assigned_by_id=ab.id WHERE otr.employee_id=? ORDER BY otr.created_at DESC");
$ot_q->bind_param('i', $employee_id); $ot_q->execute(); $ot_res = $ot_q->get_result(); $ot_q->close();
$my_requests = []; $my_assignments = [];
while ($row = $ot_res->fetch_assoc()) { if ($row['source']==='admin_assigned'||$row['request_type']==='admin_assignment') $my_assignments[]=$row; else $my_requests[]=$row; }
$total_requests=count($my_requests); $pending_requests=count(array_filter($my_requests,fn($r)=>$r['status']==='Pending'));
$approved_requests=count(array_filter($my_requests,fn($r)=>$r['status']==='Approved')); $rejected_requests=count(array_filter($my_requests,fn($r)=>$r['status']==='Rejected'));
$total_assignments=count($my_assignments); $pending_assignments=count(array_filter($my_assignments,fn($r)=>$r['status']==='Pending'));
$monthly_remaining=check_monthly_overtime_remaining($conn,$employee_id,mmt_date());
$emp_bs=$conn->query("SELECT basic_salary FROM employee WHERE id=$employee_id")->fetch_assoc(); $basic_salary=$emp_bs['basic_salary']??0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HNIN AKARI NWE &middot; Overtime Request</title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<?php include "../includes/header.php"; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<style>
.fp-calendar .day.OT-APPROVED{background:rgba(16,185,129,.25)!important;border-radius:50%}
.fp-calendar .day.OT-PENDING{background:rgba(245,158,11,.25)!important;border-radius:50%}
.fp-calendar .day.OT-REJECTED{background:rgba(239,68,68,.2)!important;border-radius:50%}
.fp-calendar .day.OT-LEAVE{background:rgba(59,130,246,.25)!important;border-radius:50%;text-decoration:line-through}
.fp-calendar .day.OT-HOLIDAY{background:rgba(244,63,94,.2)!important;border-radius:50%}
.fp-calendar .day.OT-ABSENT{background:rgba(239,68,68,.15)!important;border-radius:50%}
.fp-calendar .day.disabled-date{color:#52525b!important;opacity:.4;pointer-events:none}
</style>
</head>
<body x-data="otForm()" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
<?php $use_sidebar=true; include "../includes/sidebar.php"; ?>
<div class="main-wrapper flex flex-col min-h-screen">
<?php $page_title="Overtime Request"; $page_subtitle=format_mmt(mmt_date(),'l, F j, Y').' (MMT) &middot; <span class="text-purple-400 font-semibold">'.number_format($monthly_remaining['remaining_hours'],1).'h remaining</span>'; include "../includes/topbar.php"; ?>
<main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full">
<?php if($is_inactive): ?><div class="px-4 py-3 rounded-lg border bg-red-500/10 border-red-500/20 text-red-400"><i class="fa-solid fa-ban mr-2"></i> Your account is inactive.</div><?php endif; ?>
<?php if($message): ?><div class="px-4 py-3 rounded-lg border <?php echo $message_type=='success'?'bg-emerald-500/10 border-emerald-500/20 text-emerald-400':'bg-red-500/10 border-red-500/20 text-red-400'; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<?php if(isset($_SESSION['ot_success'])): ?><div class="px-4 py-3 rounded-lg border bg-emerald-500/10 border-emerald-500/20 text-emerald-400"><i class="fa-solid fa-circle-check mr-2"></i><?php echo htmlspecialchars($_SESSION['ot_success']); ?></div><?php unset($_SESSION['ot_success']); endif; ?>
<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
<div class="glass-strong rounded-xl p-4 border border-white/[0.06]"><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Monthly Cap</span><p class="text-lg font-bold text-white"><?php echo number_format($monthly_remaining['monthly_max'],0); ?>h</p></div>
<div class="glass-strong rounded-xl p-4 border border-white/[0.06]"><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Used</span><p class="text-lg font-bold text-amber-400"><?php echo number_format($monthly_remaining['used_hours'],1); ?>h</p></div>
<div class="glass-strong rounded-xl p-4 border border-white/[0.06]"><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Approved</span><p class="text-lg font-bold text-emerald-400"><?php echo number_format($monthly_remaining['approved_hours'],1); ?>h</p></div>
<div class="glass-strong rounded-xl p-4 border border-white/[0.06]"><span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Remaining</span><p class="text-lg font-bold text-purple-400"><?php echo number_format($monthly_remaining['remaining_hours'],1); ?>h</p></div>
</div>
<!-- Quick Stats -->
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
<div class="glass-strong rounded-xl p-3 border border-blue-500/20 bg-blue-500/5"><span class="text-[10px] font-bold uppercase tracking-wider text-blue-400">My Requests</span><p class="text-lg font-bold text-white"><?php echo $total_requests; ?></p></div>
<div class="glass-strong rounded-xl p-3 border border-amber-500/20 bg-amber-500/5"><span class="text-[10px] font-bold uppercase tracking-wider text-amber-400">Pending</span><p class="text-lg font-bold text-white"><?php echo $pending_requests; ?></p></div>
<div class="glass-strong rounded-xl p-3 border border-emerald-500/20 bg-emerald-500/5"><span class="text-[10px] font-bold uppercase tracking-wider text-emerald-400">Approved</span><p class="text-lg font-bold text-white"><?php echo $approved_requests; ?></p></div>
<div class="glass-strong rounded-xl p-3 border border-rose-500/20 bg-rose-500/5"><span class="text-[10px] font-bold uppercase tracking-wider text-rose-400">Rejected</span><p class="text-lg font-bold text-white"><?php echo $rejected_requests; ?></p></div>
<div class="glass-strong rounded-xl p-3 border border-purple-500/20 bg-purple-500/5"><span class="text-[10px] font-bold uppercase tracking-wider text-purple-400">Assignments</span><p class="text-lg font-bold text-white"><?php echo $total_assignments; ?><?php if($pending_assignments>0):?> <span class="text-xs text-amber-400">(<?php echo $pending_assignments; ?> new)</span><?php endif;?></p></div>
</div>
<!-- Calendar Legend -->
<div class="glass-strong rounded-xl p-3 border border-white/[0.06]">
<div class="flex flex-wrap items-center gap-3 text-[10px] font-semibold uppercase tracking-wider">
<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-500/30 border border-emerald-500/50"></span> OT Approved</span>
<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-amber-500/30 border border-amber-500/50"></span> OT Pending</span>
<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-blue-500/30 border border-blue-500/50"></span> Leave Day</span>
<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-rose-500/30 border border-rose-500/50"></span> Holiday</span>
<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-red-500/20 border border-red-500/40"></span> Absent</span>
<span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-zinc-500/20 border border-zinc-500/40"></span> Disabled</span>
</div></div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<!-- Form -->
<div class="space-y-4">
<div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
<h3 class="font-bold text-white mb-4"><i class="fa-solid fa-plus-circle text-blue-400 mr-2"></i>New Overtime Request</h3>
<form method="POST" class="space-y-4 text-zinc-300" @submit.prevent="submitForm">
<?php echo csrf_field(); ?>
<div>
<label class="text-xs font-semibold text-zinc-400 block mb-1">OT Date</label>
<input type="text" name="ot_date" id="ot_date_picker" required readonly x-model="otDate" class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500 cursor-pointer" placeholder="Click to select date...">
<template x-if="dateInfo && dateInfo.disable_reason"><div class="mt-2 px-3 py-2 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-xs flex items-center gap-2"><i class="fa-solid fa-circle-exclamation"></i><span x-text="dateInfo.disable_reason"></span></div></template>
<template x-if="dateInfo && !dateInfo.disabled"><div class="mt-2 space-y-1">
<div class="flex items-center gap-2 text-xs" x-show="dateInfo.is_weekend"><span class="text-amber-400"><i class="fa-solid fa-calendar-weekend mr-1"></i>Weekend OT (09:00-17:00, max 8h)</span></div>
<div class="flex items-center gap-2 text-xs" x-show="dateInfo.is_holiday"><span class="text-rose-400"><i class="fa-solid fa-calendar-day mr-1"></i>Holiday OT (09:00-17:00, max 8h) - <span x-text="dateInfo.holiday_name"></span></span></div>
<div class="flex items-center gap-2 text-xs" x-show="!dateInfo.is_weekend && !dateInfo.is_holiday"><span class="text-blue-400"><i class="fa-solid fa-briefcase mr-1"></i>Working Day OT (17:00-21:00, max 4h)</span></div>
</div></template>
</div>
<div x-show="otType" x-transition>
<label class="text-xs font-semibold text-zinc-400 block mb-1">Time Range (MMT)</label>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-[10px] text-zinc-500 block mb-1">Start Time</label>
<select name="start_time" x-model="startTime" @change="calculatePreview" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500"><option value="">Select...</option><template x-for="t in allowedStartTimes" :key="t"><option :value="t" x-text="fmt12(t)"></option></template></select></div>
<div><label class="text-[10px] text-zinc-500 block mb-1">End Time</label>
<select name="end_time" x-model="endTime" @change="calculatePreview" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500"><option value="">Select...</option><template x-for="t in allowedEndTimes" :key="t"><option :value="t" x-text="fmt12(t)"></option></template></select></div>
</div></div>
<template x-if="preview.show"><div class="rounded-xl p-4 border" :class="preview.valid ? 'bg-emerald-500/10 border-emerald-500/20' : 'bg-red-500/10 border-red-500/20'">
<div class="text-center space-y-1">
<p class="text-sm font-medium" :class="preview.valid ? 'text-emerald-400' : 'text-red-400'" x-text="preview.message"></p>
<template x-if="preview.valid"><div class="text-xs text-zinc-400">Hours: <span class="text-white font-semibold" x-text="preview.hours+'h'"></span> &middot; Rate: <span class="text-white font-semibold" x-text="preview.rate"></span> &middot; Pay: <span class="text-emerald-400 font-semibold" x-text="'$'+preview.pay"></span></div></template>
</div></div></template>
<template x-if="monthlyInfo && monthlyInfo.remaining_hours <= 4 && monthlyInfo.remaining_hours > 0"><div class="rounded-xl p-3 border bg-amber-500/10 border-amber-500/20 text-amber-400 text-xs flex items-center gap-2"><i class="fa-solid fa-triangle-exclamation"></i><span>Only <span x-text="monthlyInfo.remaining_hours.toFixed(1)"></span>h remaining this month.</span></div></template>
<template x-if="monthlyInfo && monthlyInfo.remaining_hours <= 0"><div class="rounded-xl p-3 border bg-red-500/10 border-red-500/20 text-red-400 text-xs flex items-center gap-2"><i class="fa-solid fa-ban"></i><span>Monthly overtime limit exceeded.</span></div></template>
<div><label class="text-xs font-semibold text-zinc-400 block mb-1">Reason</label>
<textarea name="reason" rows="3" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500 resize-none" placeholder="Why is overtime needed..."></textarea></div>
<?php if($has_approver_id): ?><div class="grid grid-cols-2 gap-3">
<div><label class="text-xs font-semibold text-zinc-400 block mb-1">Approver</label><select name="approver_id" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500"><option value="">Select</option><?php foreach($admins as $a):?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option><?php endforeach;?></select></div>
<div><label class="text-xs font-semibold text-zinc-400 block mb-1">Approver Type</label><select name="approver_type" required class="w-full text-sm px-3 py-3 border border-white/10 rounded-lg bg-white/[0.06] text-white focus:outline-blue-500"><option value="admin">Admin</option><option value="supervisor">Supervisor</option><option value="manager">Manager</option></select></div>
</div><?php endif; ?>
<?php if($is_inactive):?><button type="button" disabled class="w-full bg-zinc-600/50 text-zinc-400 font-semibold text-sm px-4 py-3 rounded-lg cursor-not-allowed"><i class="fa-solid fa-ban"></i> Account Inactive</button>
<?php else:?><button type="submit" name="submit_ot" :disabled="!canSubmit" class="w-full font-semibold text-sm px-4 py-3 rounded-lg transition flex items-center justify-center gap-2" :class="canSubmit ? 'bg-blue-600 hover:bg-blue-700 text-white' : 'bg-zinc-600/50 text-zinc-400 cursor-not-allowed'"><i class="fa-solid fa-clock"></i> Submit OT Request</button><?php endif;?>
</form></div>
<!-- Assignments -->
<div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
<h3 class="font-bold text-white mb-4"><i class="fa-solid fa-user-shield text-purple-400 mr-2"></i>Overtime Assignments</h3>
<?php if(empty($my_assignments)):?><div class="text-center py-8"><div class="w-16 h-16 rounded-2xl bg-purple-500/10 flex items-center justify-center mx-auto mb-3"><i class="fa-solid fa-inbox text-2xl text-zinc-500"></i></div><p class="text-zinc-400 text-sm">No assignments yet.</p></div>
<?php else:?><div class="space-y-3 max-h-[400px] overflow-y-auto pr-1"><?php foreach($my_assignments as $a):?>
<div class="rounded-xl border p-4 <?php echo $a['status']==='Pending'?'border-amber-500/30 bg-amber-500/5':($a['status']==='Approved'?'border-emerald-500/20 bg-emerald-500/5':'border-white/[0.06] bg-white/[0.02]'); ?>">
<div class="flex items-start justify-between mb-2"><div class="flex items-center gap-2"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold <?php echo $a['status']==='Pending'?'bg-amber-500/20 text-amber-400':''; ?><?php echo $a['status']==='Approved'?'bg-emerald-500/20 text-emerald-400':''; ?><?php echo $a['status']==='Rejected'?'bg-red-500/20 text-red-400':''; ?>"><?php echo $a['status']; ?></span><span class="text-xs text-zinc-500"><?php echo format_mmt($a['ot_date'],'M d, Y'); ?></span></div></div>
<div class="grid grid-cols-2 gap-2 text-xs mb-2"><div><span class="text-zinc-500">Time:</span> <span class="text-white ml-1"><?php echo date('h:i A',strtotime($a['start_time'])).' - '.date('h:i A',strtotime($a['end_time'])); ?></span></div><div><span class="text-zinc-500">Hours:</span> <span class="text-white font-semibold ml-1"><?php echo $a['total_hours']; ?>h</span></div></div>
<?php if(!empty($a['assigned_by_emp_name'])):?><div class="text-xs mb-2"><span class="text-zinc-500">Assigned by:</span> <span class="text-blue-400 ml-1"><?php echo htmlspecialchars($a['assigned_by_emp_name']); ?></span></div><?php endif;?>
<?php if($a['status']==='Pending'):?><div class="flex gap-2 mt-3 pt-3 border-t border-white/[0.06]"><form method="POST" class="flex-1"><?php echo csrf_field();?><input type="hidden" name="request_id" value="<?php echo $a['id'];?>"><input type="hidden" name="response" value=""><button type="submit" name="respond_ot" value="1" onclick="this.form.response.value='accepted'" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium text-xs px-3 py-2 rounded-lg"><i class="fa-solid fa-check"></i> Accept</button></form><form method="POST" class="flex-1"><?php echo csrf_field();?><input type="hidden" name="request_id" value="<?php echo $a['id'];?>"><input type="hidden" name="response" value=""><button type="submit" name="respond_ot" value="1" onclick="this.form.response.value='rejected'" class="w-full border border-red-500/30 hover:bg-red-500/10 text-red-400 font-medium text-xs px-3 py-2 rounded-lg"><i class="fa-solid fa-times"></i> Decline</button></form></div><?php endif;?>
<!-- Requests History -->
<div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
<h3 class="font-bold text-white mb-4"><i class="fa-solid fa-history text-sky-400 mr-2"></i>My Overtime Requests</h3>
<?php if(empty($my_requests)):?><div class="text-center py-8"><div class="w-16 h-16 rounded-2xl bg-sky-500/10 flex items-center justify-center mx-auto mb-3"><i class="fa-solid fa-inbox text-2xl text-zinc-500"></i></div><p class="text-zinc-400 text-sm">No overtime requests yet.</p></div>
<?php else:?><div class="space-y-3 max-h-[700px] overflow-y-auto pr-1"><?php foreach($my_requests as $row):?>
<div class="rounded-xl border p-4 <?php echo $row['status']==='Pending'?'border-amber-500/30 bg-amber-500/5':($row['status']==='Approved'?'border-emerald-500/20 bg-emerald-500/5':($row['status']==='Rejected'?'border-red-500/20 bg-red-500/5':'border-white/[0.06] bg-white/[0.02]')); ?>">
<div class="flex items-start justify-between mb-2"><div class="flex items-center gap-2">
<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold <?php echo $row['status']=='Approved'?'bg-emerald-500/20 text-emerald-400':''; ?><?php echo $row['status']=='Rejected'?'bg-red-500/20 text-red-400':''; ?><?php echo $row['status']=='Pending'?'bg-amber-500/20 text-amber-400':''; ?><?php echo $row['status']=='Cancelled'?'bg-zinc-500/20 text-zinc-400':''; ?>"><?php echo $row['status']; ?></span>
<span class="text-xs text-zinc-500"><?php echo format_mmt($row['ot_date'],'M d, Y'); ?></span></div>
<?php if($row['status']==='Pending'):?><form method="POST" class="inline"><?php echo csrf_field();?><input type="hidden" name="request_id" value="<?php echo $row['id'];?>"><button type="submit" name="cancel_ot" value="1" onclick="return confirm('Cancel this request?')" class="text-xs text-red-400 hover:text-red-300"><i class="fa-solid fa-times mr-1"></i>Cancel</button></form><?php endif;?></div>
<div class="grid grid-cols-2 gap-2 text-xs mb-1">
<div><span class="text-zinc-500">Time:</span> <span class="text-white ml-1"><?php echo date('h:i A',strtotime($row['start_time'])).' - '.date('h:i A',strtotime($row['end_time'])); ?></span></div>
<div><span class="text-zinc-500">Hours:</span> <span class="text-white font-semibold ml-1"><?php echo $row['total_hours']; ?>h</span></div>
<div><span class="text-zinc-500">Type:</span> <?php if($has_ot_type&&$row['ot_type']):?><?php echo get_overtime_type_badge($row['ot_type']);?><?php else:?><span class="text-zinc-500 ml-1">-</span><?php endif;?></div>
<div><span class="text-zinc-500">Pay:</span> <?php if($has_ot_pay&&isset($row['ot_pay'])&&$row['ot_pay']>0):?><span class="text-emerald-400 font-semibold ml-1">$<?php echo number_format($row['ot_pay'],2);?></span><?php else:?><span class="text-zinc-500 ml-1">-</span><?php endif;?></div>
</div>
<?php if($has_approver_id&&!empty($row['approver_name'])):?><div class="text-xs"><span class="text-zinc-500">Approver:</span> <span class="text-blue-400 ml-1"><?php echo htmlspecialchars($row['approver_name']);?></span></div><?php endif;?>
<?php if($row['approved_at']):?><div class="text-xs text-zinc-500 mt-1"><i class="fa-solid fa-clock mr-1"></i><?php echo date('M d, Y h:i A',strtotime($row['approved_at']));?></div><?php endif;?>
</div><?php endforeach;?></div><?php endif;?>
</div>
</div>
</main>
</div>
<script>
function otForm(){return{
otDate:'',startTime:'',endTime:'',otType:'',dateInfo:null,calendarData:{},monthlyInfo:null,
preview:{show:false,valid:false,hours:'0.0',rate:'0.02',pay:'0.00',message:''},
rateMultipliers:{working_day:0.02,weekend:0.03,holiday:0.04},
basicSalary:<?php echo $basic_salary??0;?>,
get allowedStartTimes(){if(this.otType==='working_day')return this.genSlots('17:00','20:30',30);return this.genSlots('09:00','16:30',30);},
get allowedEndTimes(){if(!this.startTime)return[];if(this.otType==='working_day')return this.genSlots('17:30','21:00',30);return this.genSlots('09:30','17:00',30);},
get canSubmit(){return this.otDate&&this.startTime&&this.endTime&&this.preview.valid&&(!this.dateInfo||!this.dateInfo.disabled)&&this.monthlyInfo&&this.monthlyInfo.remaining_hours>0;},
genSlots(s,e,m){const r=[];let[h,mi]=s.split(':').map(Number);const[eh,em]=e.split(':').map(Number);while(h<eh||(h===eh&&mi<=em)){r.push(String(h).padStart(2,'0')+':'+String(mi).padStart(2,'0'));mi+=m;if(mi>=60){h++;mi-=60;}}return r;},
fmt12(t){const[h,m]=t.split(':').map(Number);return(h%12||12)+':'+String(m).padStart(2,'0')+(h>=12?' PM':' AM');},
loadCalData(y,m){fetch('../ajax/ot_calendar_data.php?year='+y+'&month='+m).then(r=>r.json()).then(d=>{this.calendarData=d.dates||{};this.monthlyInfo=d.monthly||null;this.applyClasses();});},
applyClasses(){const self=this;setTimeout(()=>{document.querySelectorAll('.flatpickr-day').forEach(day=>{const lbl=day.getAttribute('aria-label');if(!lbl)return;const iso=new Date(lbl).toISOString().split('T')[0];const info=self.calendarData[iso];if(!info)return;day.classList.remove('OT-APPROVED','OT-PENDING','OT-REJECTED','OT-LEAVE','OT-HOLIDAY','OT-ABSENT','disabled-date');if(info.disabled&&!info.is_past)day.classList.add('disabled-date');if(info.has_leave)day.classList.add('OT-LEAVE');else if(info.is_holiday)day.classList.add('OT-HOLIDAY');else if(info.ot_requests.length>0){const st=info.ot_requests.map(r=>r.status);if(st.includes('Approved'))day.classList.add('OT-APPROVED');else if(st.includes('Pending'))day.classList.add('OT-PENDING');else if(st.includes('Rejected'))day.classList.add('OT-REJECTED');}});},100);},
onDateChange(){this.startTime='';this.endTime='';this.preview={show:false,valid:false,hours:'0.0',rate:'0',pay:'0.00',message:''};if(!this.otDate){this.otType='';this.dateInfo=null;return;}const info=this.calendarData[this.otDate];this.dateInfo=info||null;if(info&&info.disabled){this.otType='';return;}fetch('../ajax/detect_ot_type.php?date='+this.otDate).then(r=>r.json()).then(d=>{this.otType=d.type;}).catch(()=>{this.otType='';});},
calculatePreview(){if(!this.otDate||!this.startTime||!this.endTime||!this.otType){this.preview.show=false;return;}const s=new Date('2000-01-01T'+this.startTime+':00');const e=new Date('2000-01-01T'+this.endTime+':00');if(e<=s){this.preview={show:true,valid:false,hours:'0.0',rate:'0',pay:'0.00',message:'End time must be after start time'};return;}const hrs=(e-s)/3600000;const rate=this.rateMultipliers[this.otType]||0.02;const hr=this.basicSalary>0?(this.basicSalary/(30*8)):0;const pay=hr*rate*hrs;const maxH=this.otType==='working_day'?4:8;const w={working_day:{s:'17:00',e:'21:00'},weekend:{s:'09:00',e:'17:00'},holiday:{s:'09:00',e:'17:00'}}[this.otType];const valid=hrs>0&&hrs<=maxH&&this.startTime>=w.s&&this.endTime<=w.e;let msg='Valid OT request';if(!valid){if(hrs>maxH)msg='Exceeds max '+maxH+'h';else if(this.startTime<w.s||this.endTime>w.e)msg='Outside allowed window ('+w.s+' - '+w.e+')';else msg='Check your time selection';}if(this.monthlyInfo&&(this.monthlyInfo.used_hours+hrs)>this.monthlyInfo.monthly_max){this.preview={show:true,valid:false,hours:hrs.toFixed(1),rate:rate.toFixed(2),pay:pay.toFixed(2),message:'Monthly OT limit exceeded. Would be '+(this.monthlyInfo.used_hours+hrs).toFixed(1)+'h / '+this.monthlyInfo.monthly_max+'h'};return;}this.preview={show:true,valid:valid,hours:hrs.toFixed(1),rate:rate.toFixed(2),pay:pay.toFixed(2),message:msg};},
submitForm(e){if(!this.canSubmit){e.preventDefault();return;}e.target.submit();},
init(){const self=this;flatpickr('#ot_date_picker',{dateFormat:'Y-m-d',minDate:'<?php echo mmt_date();?>',locale:{firstDayOfWeek:1},disableMobile:true,onMonthChange:function(s,d,fp){self.loadCalData(fp.currentYear,fp.currentMonth+1);},onYearChange:function(s,d,fp){self.loadCalData(fp.currentYear,fp.currentMonth+1);},onChange:function(s,d){self.otDate=d;self.onDateChange();},onReady:function(s,d,fp){self.loadCalData(fp.currentYear,fp.currentMonth+1);}});}
}
</script>
<?php include "../includes/employee_bottom_nav.php"; ?>
</body></html>
