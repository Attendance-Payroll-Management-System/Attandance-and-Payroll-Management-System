<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
require_once "../config/notifications.php";

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];
set_mmt_timezone();

$unread_notifications = get_unread_count($conn, $employee_id);
$notifications = get_notifications($conn, $employee_id, 5);

// Fetch profile photo
$photo_stmt = $conn->prepare("SELECT profile_photo FROM employee WHERE id = ?");
$photo_stmt->bind_param('i', $employee_id);
$photo_stmt->execute();
$employee_photo = $photo_stmt->get_result()->fetch_assoc()['profile_photo'] ?? '';
$photo_stmt->close();

// View mode: daily, weekly, monthly
$view_mode = $_GET['view'] ?? 'daily';
if (!in_array($view_mode, ['daily', 'weekly', 'monthly'])) $view_mode = 'daily';

// Date handling
$today = mmt_date();

if ($view_mode === 'daily') {
    $selected_date = isset($_GET['date']) ? preg_replace('/[^0-9\-]/', '', $_GET['date']) : $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) $selected_date = $today;
    $prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
    $next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));
    $is_today = ($selected_date === $today);
    $page_date_label = date('l, F j, Y', strtotime($selected_date));
} elseif ($view_mode === 'weekly') {
    $selected_date = isset($_GET['date']) ? preg_replace('/[^0-9\-]/', '', $_GET['date']) : $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) $selected_date = $today;
    $day_of_week = (int)date('N', strtotime($selected_date));
    $week_start = date('Y-m-d', strtotime($selected_date . ' -' . ($day_of_week - 1) . ' days'));
    $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
    $prev_week = date('Y-m-d', strtotime($week_start . ' -7 days'));
    $next_week = date('Y-m-d', strtotime($week_start . ' +7 days'));
    $is_current_week = ($week_start <= $today && $week_end >= $today);
    $page_date_label = date('M j', strtotime($week_start)) . ' - ' . date('M j, Y', strtotime($week_end));
} else {
    $selected_month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', $_GET['month']) : date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) $selected_month = date('Y-m');
    $month_start = $selected_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $prev_month = date('Y-m', strtotime($month_start . ' -1 month'));
    $next_month = date('Y-m', strtotime($month_start . ' +1 month'));
    $is_current_month = ($selected_month === date('Y-m'));
    $page_date_label = date('F Y', strtotime($month_start));
}

// ─── Fetch Attendance Data ───
function fetch_attendance_for_range($conn, $employee_id, $from, $to) {
    $stmt = $conn->prepare("SELECT attendance_date, check_in, check_out, status, total_working_hours, is_late FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date ASC");
    $stmt->bind_param('iss', $employee_id, $from, $to);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

// ─── Fetch Overtime Data (from both tables) ───
function fetch_overtime_for_range($conn, $employee_id, $from, $to) {
    $ot = [];
    // overtime_requests
    $has_ot_type = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_type'")->num_rows > 0;
    $has_ot_pay = $conn->query("SHOW COLUMNS FROM overtime_requests LIKE 'ot_pay'")->num_rows > 0;

    $cols = "ot_date, start_time, end_time, total_hours, status";
    if ($has_ot_type) $cols .= ", ot_type";
    if ($has_ot_pay) $cols .= ", ot_pay";

    $stmt = $conn->prepare("SELECT $cols FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
    $stmt->bind_param('iss', $employee_id, $from, $to);
    $stmt->execute();
    $ot_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($ot_requests as $r) {
        $key = $r['ot_date'];
        if (!isset($ot[$key])) $ot[$key] = [];
        $ot[$key][] = [
            'start_time' => $r['start_time'],
            'end_time' => $r['end_time'],
            'total_hours' => $r['total_hours'],
            'ot_type' => $has_ot_type ? ($r['ot_type'] ?? 'working_day') : 'working_day',
            'ot_pay' => $has_ot_pay ? ($r['ot_pay'] ?? 0) : 0,
            'source' => 'request',
        ];
    }

    // overtime_records (admin-assigned)
    $stmt2 = $conn->prepare("SELECT ot_date, start_time, end_time, total_hours, ot_type, ot_pay, status FROM overtime_records WHERE employee_id = ? AND ot_date BETWEEN ? AND ?");
    $stmt2->bind_param('iss', $employee_id, $from, $to);
    $stmt2->execute();
    $ot_records = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    foreach ($ot_records as $r) {
        $key = $r['ot_date'];
        if (!isset($ot[$key])) $ot[$key] = [];
        $ot[$key][] = [
            'start_time' => $r['start_time'],
            'end_time' => $r['end_time'],
            'total_hours' => $r['total_hours'],
            'ot_type' => $r['ot_type'] ?? 'working_day',
            'ot_pay' => $r['ot_pay'] ?? 0,
            'source' => 'record',
        ];
    }

    return $ot;
}

// ─── Overtime type label helper ───
function ot_type_label($type) {
    return match($type) {
        'working_day', 'weekday' => 'Working Day OT',
        'weekend' => 'Weekend OT',
        'holiday' => 'Holiday OT',
        default => ucfirst(str_replace('_', ' ', $type)) . ' OT',
    };
}

function ot_type_color($type) {
    return match($type) {
        'working_day', 'weekday' => ['bg' => 'bg-blue-500/10', 'border' => 'border-blue-500/20', 'text' => 'text-blue-400', 'dot' => 'bg-blue-400'],
        'weekend' => ['bg' => 'bg-amber-500/10', 'border' => 'border-amber-500/20', 'text' => 'text-amber-400', 'dot' => 'bg-amber-400'],
        'holiday' => ['bg' => 'bg-rose-500/10', 'border' => 'border-rose-500/20', 'text' => 'text-rose-400', 'dot' => 'bg-rose-400'],
        default => ['bg' => 'bg-zinc-500/10', 'border' => 'border-zinc-500/20', 'text' => 'text-zinc-400', 'dot' => 'bg-zinc-400'],
    };
}

function format_time_12($time) {
    if (!$time) return '-';
    return date('h:i A', strtotime($time));
}

// Build data based on view mode
$daily_data = null;
$weekly_data = [];
$monthly_data = [];

if ($view_mode === 'daily') {
    $att = fetch_attendance_for_range($conn, $employee_id, $selected_date, $selected_date);
    $ot = fetch_overtime_for_range($conn, $employee_id, $selected_date, $selected_date);
    $daily_data = [
        'attendance' => $att[0] ?? null,
        'overtime' => $ot[$selected_date] ?? [],
    ];
} elseif ($view_mode === 'weekly') {
    $att_records = fetch_attendance_for_range($conn, $employee_id, $week_start, $week_end);
    $ot_records = fetch_overtime_for_range($conn, $employee_id, $week_start, $week_end);
    $att_map = [];
    foreach ($att_records as $a) $att_map[$a['attendance_date']] = $a;

    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($week_start . " +$i days"));
        $weekly_data[$d] = [
            'date' => $d,
            'day_name' => date('D', strtotime($d)),
            'day_num' => date('j', strtotime($d)),
            'month_short' => date('M', strtotime($d)),
            'is_today' => ($d === $today),
            'is_weekend' => ((int)date('N', strtotime($d))) >= 6,
            'attendance' => $att_map[$d] ?? null,
            'overtime' => $ot_records[$d] ?? [],
        ];
    }
} else {
    $att_records = fetch_attendance_for_range($conn, $employee_id, $month_start, $month_end);
    $ot_records = fetch_overtime_for_range($conn, $employee_id, $month_start, $month_end);

    // Monthly summary stats
    $total_days_in_month = (int)date('t', strtotime($month_start));
    $present_count = 0;
    $late_count = 0;
    $absent_count = 0;
    $leave_count = 0;
    $half_day_count = 0;
    $weekend_count = 0;
    $holiday_count = 0;
    $total_ot_hours = 0;
    $total_ot_pay = 0;
    $total_working_hours = 0;

    $day_map = [];
    foreach ($att_records as $a) {
        $day_map[$a['attendance_date']] = $a;
        $s = $a['status'];
        if (in_array($s, ['present', 'late'])) $present_count++;
        if ($s === 'late') $late_count++;
        if (in_array($s, ['absent', 'full_absent', 'half_absent', 'awol'])) $absent_count++;
        if (in_array($s, ['leave', 'paid_leave', 'unpaid_leave'])) $leave_count++;
        if ($s === 'half_day') { $half_day_count++; $present_count++; }
        if ($s === 'weekend') $weekend_count++;
        if ($s === 'public_holiday') $holiday_count++;
        if ($a['total_working_hours']) $total_working_hours += (float)$a['total_working_hours'];
    }

    $day_by_day = [];
    for ($d = 1; $d <= $total_days_in_month; $d++) {
        $date = sprintf('%s-%02d', $selected_month, $d);
        if (strtotime($date) > strtotime($today)) break;
        $day_by_day[$date] = [
            'date' => $date,
            'day_num' => $d,
            'day_name' => date('D', strtotime($date)),
            'is_weekend' => ((int)date('N', strtotime($date))) >= 6,
            'attendance' => $day_map[$date] ?? null,
            'overtime' => $ot_records[$date] ?? [],
        ];
        if (isset($ot_records[$date])) {
            foreach ($ot_records[$date] as $o) {
                $total_ot_hours += (float)$o['total_hours'];
                $total_ot_pay += (float)$o['ot_pay'];
            }
        }
    }

    $monthly_data = [
        'present' => $present_count,
        'late' => $late_count,
        'absent' => $absent_count,
        'leave' => $leave_count,
        'half_day' => $half_day_count,
        'weekend' => $weekend_count,
        'holiday' => $holiday_count,
        'total_ot_hours' => $total_ot_hours,
        'total_ot_pay' => $total_ot_pay,
        'total_working_hours' => $total_working_hours,
        'total_days_in_month' => $total_days_in_month,
        'days' => $day_by_day,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance & Overtime</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .view-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            color: #94A3B8;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        :root:not(.dark) .view-tab {
            color: #64748B;
            background: rgba(0, 0, 0, 0.02);
            border-color: rgba(0, 0, 0, 0.08);
        }
        .view-tab:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #E2E8F0;
        }
        :root:not(.dark) .view-tab:hover {
            background: rgba(0, 0, 0, 0.04);
            color: #334155;
        }
        .view-tab-active {
            background: rgba(99, 102, 241, 0.2) !important;
            border-color: rgba(99, 102, 241, 0.3) !important;
            color: #818CF8 !important;
        }
        :root:not(.dark) .view-tab-active {
            background: rgba(79, 70, 229, 0.1) !important;
            border-color: rgba(79, 70, 229, 0.25) !important;
            color: #4F46E5 !important;
        }

        .detail-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }
        :root:not(.dark) .detail-row {
            border-bottom-color: rgba(0,0,0,0.06);
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #94A3B8;
            font-weight: 500;
        }
        :root:not(.dark) .detail-label { color: #64748B; }
        .detail-value {
            font-size: 14px;
            font-weight: 700;
            color: #F1F5F9;
        }
        :root:not(.dark) .detail-value { color: #1E293B; }

        .week-day-card {
            border-radius: 14px;
            padding: 16px;
            border: 1px solid rgba(255,255,255,0.06);
            background: rgba(255,255,255,0.02);
            transition: all 0.2s;
        }
        :root:not(.dark) .week-day-card {
            border-color: rgba(0,0,0,0.08);
            background: rgba(255,255,255,0.8);
        }
        .week-day-card:hover {
            border-color: rgba(99,102,241,0.3);
            background: rgba(99,102,241,0.04);
        }
        .week-day-card.is-today {
            border-color: rgba(99,102,241,0.5);
            box-shadow: 0 0 0 1px rgba(99,102,241,0.2), 0 4px 16px rgba(99,102,241,0.1);
        }
        .week-day-card.is-weekend {
            opacity: 0.6;
        }

        .ot-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .month-table td, .month-table th {
            padding: 10px 14px;
            font-size: 13px;
        }

        @media (max-width: 640px) {
            .week-day-card { padding: 12px; }
            .detail-row { flex-direction: column; align-items: flex-start; gap: 4px; padding: 8px 0; }
            .detail-label { font-size: 12px; }
            .detail-value { font-size: 13px; }
        }

        @media (max-width: 768px) {
            .month-table { font-size: 11px; }
            .month-table th, .month-table td { padding: 8px 6px; white-space: nowrap; }
        }
    </style>
</head>

<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Attendance & Overtime"; $page_subtitle = "All-in-one view"; include "../includes/topbar.php"; ?>
        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full" x-data="{ mode: '<?= $view_mode ?>' }">

            <!-- ═══ View Mode Tabs + Navigation ═══ -->
            <div class="glass-strong rounded-2xl p-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xl shadow-lg shadow-indigo-500/25">
                            <i class="fa-solid fa-calendar-week"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-extrabold text-white tracking-tight"><?= htmlspecialchars($page_date_label) ?></h3>
                            <p class="text-[11px] text-zinc-400 mt-0.5">
                                <?php if ($view_mode === 'daily' && $is_today): ?>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                        <span class="text-emerald-400 font-medium">Today</span>
                                    </span>
                                <?php elseif ($view_mode === 'weekly' && $is_current_week): ?>
                                    <span class="text-emerald-400 font-medium">Current Week</span>
                                <?php elseif ($view_mode === 'monthly' && $is_current_month): ?>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                        <span class="text-emerald-400 font-medium">Current Month</span>
                                    </span>
                                <?php else: ?>
                                    <span class="text-zinc-500">Viewing historical data</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- View Tabs -->
                    <div class="flex gap-1.5 p-1 bg-white/[0.04] rounded-xl">
                        <button @click="mode = 'daily'" class="view-tab" :class="mode === 'daily' && 'view-tab-active'">
                            <i class="fa-solid fa-sun text-xs"></i> Daily
                        </button>
                        <button @click="mode = 'weekly'" class="view-tab" :class="mode === 'weekly' && 'view-tab-active'">
                            <i class="fa-solid fa-calendar-week text-xs"></i> Weekly
                        </button>
                        <button @click="mode = 'monthly'" class="view-tab" :class="mode === 'monthly' && 'view-tab-active'">
                            <i class="fa-solid fa-calendar-days text-xs"></i> Monthly
                        </button>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center gap-2 mt-4 pt-4 border-t border-white/[0.06]">
                    <?php if ($view_mode === 'daily'): ?>
                        <a href="?view=daily&date=<?= $prev_date ?>" class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all group">
                            <i class="fa-solid fa-chevron-left text-xs text-zinc-400 group-hover:text-indigo-400"></i>
                        </a>
                        <input type="date" value="<?= $selected_date ?>" onchange="window.location.href='?view=daily&date=' + this.value"
                            class="h-9 px-3 glass rounded-lg border border-white/[0.08] text-sm text-white text-center font-semibold cursor-pointer focus:outline-none focus:border-indigo-500/50 transition-all">
                        <a href="?view=daily&date=<?= $next_date ?>" class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all group">
                            <i class="fa-solid fa-chevron-right text-xs text-zinc-400 group-hover:text-indigo-400"></i>
                        </a>
                        <?php if (!$is_today): ?>
                            <a href="?view=daily" class="h-9 px-4 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-emerald-500/40 hover:bg-emerald-500/10 transition-all group gap-1.5 ml-1">
                                <i class="fa-solid fa-crosshairs text-[10px] text-zinc-400 group-hover:text-emerald-400"></i>
                                <span class="text-xs font-semibold text-zinc-400 group-hover:text-emerald-400">Today</span>
                            </a>
                        <?php endif; ?>
                    <?php elseif ($view_mode === 'weekly'): ?>
                        <a href="?view=weekly&date=<?= $prev_week ?>" class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all group">
                            <i class="fa-solid fa-chevron-left text-xs text-zinc-400 group-hover:text-indigo-400"></i>
                        </a>
                        <span class="text-sm font-semibold text-zinc-300 px-2"><?= date('M j', strtotime($week_start)) ?> – <?= date('M j, Y', strtotime($week_end)) ?></span>
                        <a href="?view=weekly&date=<?= $next_week ?>" class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all group">
                            <i class="fa-solid fa-chevron-right text-xs text-zinc-400 group-hover:text-indigo-400"></i>
                        </a>
                        <?php if (!$is_current_week): ?>
                            <a href="?view=weekly" class="h-9 px-4 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-emerald-500/40 hover:bg-emerald-500/10 transition-all group gap-1.5 ml-1">
                                <i class="fa-solid fa-crosshairs text-[10px] text-zinc-400 group-hover:text-emerald-400"></i>
                                <span class="text-xs font-semibold text-zinc-400 group-hover:text-emerald-400">This Week</span>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="?view=monthly&month=<?= $prev_month ?>" class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all group">
                            <i class="fa-solid fa-chevron-left text-xs text-zinc-400 group-hover:text-indigo-400"></i>
                        </a>
                        <input type="month" value="<?= $selected_month ?>" onchange="window.location.href='?view=monthly&month=' + this.value"
                            class="h-9 w-44 glass rounded-lg border border-white/[0.08] text-sm text-white text-center font-semibold cursor-pointer focus:outline-none focus:border-indigo-500/50 transition-all">
                        <a href="?view=monthly&month=<?= $next_month ?>" class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all group">
                            <i class="fa-solid fa-chevron-right text-xs text-zinc-400 group-hover:text-indigo-400"></i>
                        </a>
                        <?php if (!$is_current_month): ?>
                            <a href="?view=monthly" class="h-9 px-4 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-emerald-500/40 hover:bg-emerald-500/10 transition-all group gap-1.5 ml-1">
                                <i class="fa-solid fa-crosshairs text-[10px] text-zinc-400 group-hover:text-emerald-400"></i>
                                <span class="text-xs font-semibold text-zinc-400 group-hover:text-emerald-400">This Month</span>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!-- DAILY VIEW -->
            <!-- ═══════════════════════════════════════════ -->
            <div x-show="mode === 'daily'" x-transition:enter="transition-all duration-200">
                <?php
                $att = $daily_data['attendance'];
                $ots = $daily_data['overtime'];
                $total_ot = 0;
                $total_ot_pay = 0;
                foreach ($ots as $o) {
                    $total_ot += (float)$o['total_hours'];
                    $total_ot_pay += (float)$o['ot_pay'];
                }
                ?>

                <?php if ($att || !empty($ots)): ?>
                <div class="max-w-2xl mx-auto space-y-6">

                    <!-- Unified Daily Info Card -->
                    <div class="glass-strong rounded-2xl overflow-hidden">
                        <!-- Card Header -->
                        <div class="px-6 py-5 border-b border-white/[0.06] flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-lg shadow-lg shadow-indigo-500/25">
                                    <i class="fa-solid fa-calendar-day"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-white text-sm"><?= date('l, F j, Y', strtotime($selected_date)) ?></h3>
                                    <p class="text-[11px] text-zinc-400 mt-0.5">Daily Attendance & Overtime Summary</p>
                                </div>
                            </div>
                            <?php if ($att): ?>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[11px] font-bold <?= get_attendance_status_badge_class($att['status']) ?>">
                                    <span class="w-1.5 h-1.5 rounded-full bg-current opacity-80"></span>
                                    <?= get_attendance_status_label($att['status']) ?>
                                </span>
                                <?= get_auto_checkout_badge($att['is_auto_checkout'] ?? 0) ?>
                            <?php endif; ?>
                        </div>

                        <div class="p-6">
                            <?php if ($att): ?>
                            <div class="space-y-0">
                                <!-- Status -->
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <span class="w-7 h-7 rounded-lg bg-slate-500/10 flex items-center justify-center text-slate-400"><i class="fa-solid fa-circle-info text-xs"></i></span>
                                        Status
                                    </span>
                                    <span class="detail-value">
                                        <span class="inline-flex rounded-full px-3 py-1 text-[11px] font-bold <?= get_attendance_status_badge_class($att['status']) ?>">
                                            <?= get_attendance_status_label($att['status']) ?>
                                        </span>
                                        <?= get_auto_checkout_badge($att['is_auto_checkout'] ?? 0) ?>
                                    </span>
                                </div>

                                <!-- Check-In -->
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <span class="w-7 h-7 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400"><i class="fa-solid fa-right-to-bracket text-xs"></i></span>
                                        Check-In
                                    </span>
                                    <span class="detail-value <?= $att['check_in'] ? 'text-emerald-400' : 'text-zinc-500' ?>">
                                        <?= $att['check_in'] ? format_time_12($att['check_in']) : 'Not recorded' ?>
                                    </span>
                                </div>

                                <!-- Check-Out -->
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <span class="w-7 h-7 rounded-lg bg-rose-500/10 flex items-center justify-center text-rose-400"><i class="fa-solid fa-right-from-bracket text-xs"></i></span>
                                        Check-Out
                                    </span>
                                    <span class="detail-value <?= $att['check_out'] ? 'text-rose-400' : 'text-zinc-500' ?>">
                                        <?= $att['check_out'] ? format_time_12($att['check_out']) : 'Not recorded' ?>
                                    </span>
                                </div>

                                <!-- Working Hours -->
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <span class="w-7 h-7 rounded-lg bg-indigo-500/10 flex items-center justify-center text-indigo-400"><i class="fa-solid fa-clock text-xs"></i></span>
                                        Working Hours
                                    </span>
                                    <span class="detail-value">
                                        <?php
                                        if ($att['total_working_hours']) {
                                            $wh = number_format((float)$att['total_working_hours'], 1);
                                            $wh_h = floor((float)$att['total_working_hours']);
                                            $wh_m = round(((float)$att['total_working_hours'] - $wh_h) * 60);
                                            echo '<span class="text-indigo-400">' . $wh_h . ' Hours ' . $wh_m . ' Minutes</span>';
                                        } elseif ($att['check_in'] && $att['check_out']) {
                                            $diff = (strtotime($att['check_out']) - strtotime($att['check_in'])) / 3600;
                                            $dh = floor($diff);
                                            $dm = round(($diff - $dh) * 60);
                                            echo '<span class="text-indigo-400">' . $dh . ' Hours ' . $dm . ' Minutes</span>';
                                        } else {
                                            echo '<span class="text-zinc-500">-</span>';
                                        }
                                        ?>
                                    </span>
                                </div>

                                <?php if ($att['is_late']): ?>
                                <!-- Late Arrival -->
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <span class="w-7 h-7 rounded-lg bg-amber-500/10 flex items-center justify-center text-amber-400"><i class="fa-solid fa-triangle-exclamation text-xs"></i></span>
                                        Late Arrival
                                    </span>
                                    <span class="detail-value text-amber-400">Yes</span>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($ots)): ?>
                                <!-- Divider before OT section -->
                                <div class="pt-3 mt-1 border-t border-white/[0.06]">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500 flex items-center gap-1.5 mb-1">
                                        <i class="fa-solid fa-stopwatch text-orange-400"></i> Overtime Details
                                    </span>
                                </div>

                                <?php foreach ($ots as $o):
                                    $colors = ot_type_color($o['ot_type']);
                                ?>
                                <!-- OT Hours -->
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <span class="w-7 h-7 rounded-lg <?= $colors['bg'] ?> flex items-center justify-center <?= $colors['text'] ?>"><i class="fa-solid fa-hourglass-half text-xs"></i></span>
                                        <span>
                                            OT Hours
                                            <span class="ot-chip <?= $colors['bg'] ?> <?= $colors['text'] ?> border <?= $colors['border'] ?> ml-1.5 text-[9px]">
                                                <span class="w-1.5 h-1.5 rounded-full <?= $colors['dot'] ?>"></span>
                                                <?= ot_type_label($o['ot_type']) ?>
                                            </span>
                                        </span>
                                    </span>
                                    <span class="detail-value text-orange-400"><?= number_format((float)$o['total_hours'], 1) ?> Hours</span>
                                </div>

                                <!-- OT Time Range -->
                                <?php if ($o['start_time'] && $o['end_time']): ?>
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <span class="w-7 h-7 rounded-lg bg-white/[0.04] flex items-center justify-center text-zinc-400"><i class="fa-solid fa-clock text-xs"></i></span>
                                        OT Time
                                    </span>
                                    <span class="detail-value text-zinc-300 text-[13px]">
                                        <?= format_time_12($o['start_time']) ?> – <?= format_time_12($o['end_time']) ?>
                                    </span>
                                </div>
                                <?php endif; ?>

                                <?php if ($o['ot_pay'] > 0): ?>
                                <!-- OT Pay -->
                                <div class="detail-row">
                                    <span class="detail-label">
                                        <span class="w-7 h-7 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400"><i class="fa-solid fa-dollar-sign text-xs"></i></span>
                                        OT Pay
                                    </span>
                                    <span class="detail-value text-emerald-400">$<?= number_format((float)$o['ot_pay'], 2) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>

                                <?php if (count($ots) > 1): ?>
                                <!-- Total OT Summary -->
                                <div class="pt-3 mt-1 border-t border-white/[0.06]">
                                    <div class="flex items-center justify-between p-3 rounded-xl bg-orange-500/5 border border-orange-500/15">
                                        <span class="text-xs font-bold text-zinc-400">Total OT</span>
                                        <div class="text-right">
                                            <span class="text-sm font-extrabold text-orange-400"><?= number_format($total_ot, 1) ?>h</span>
                                            <?php if ($total_ot_pay > 0): ?>
                                                <span class="text-xs font-bold text-emerald-400 ml-2">$<?= number_format($total_ot_pay, 2) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <!-- No Attendance Record -->
                            <div class="py-6 text-center">
                                <div class="w-14 h-14 rounded-2xl bg-white/[0.03] flex items-center justify-center mx-auto mb-3">
                                    <i class="fa-solid fa-calendar-xmark text-xl text-zinc-600"></i>
                                </div>
                                <p class="text-sm text-zinc-400 font-medium">No attendance record</p>
                                <p class="text-xs text-zinc-500 mt-1">You did not check in on this day.</p>
                                <?php if (!empty($ots)): ?>
                                <div class="mt-4 p-3 rounded-xl bg-orange-500/5 border border-orange-500/15 text-xs text-orange-400 font-medium">
                                    <i class="fa-solid fa-circle-info mr-1"></i> Overtime records are still available below.
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$att && !empty($ots)): ?>
                    <!-- Standalone OT Card (when no attendance but OT exists) -->
                    <div class="glass-strong rounded-2xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-white/[0.06] flex items-center gap-3">
                            <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-orange-500/20 to-amber-500/20 text-orange-400 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-stopwatch"></i>
                            </span>
                            <h3 class="font-bold text-white text-sm">Overtime Records</h3>
                        </div>
                        <div class="p-6 space-y-3">
                            <?php foreach ($ots as $o):
                                $colors = ot_type_color($o['ot_type']);
                            ?>
                            <div class="flex items-center justify-between p-3 rounded-xl border <?= $colors['border'] ?> <?= $colors['bg'] ?>">
                                <div class="flex items-center gap-3">
                                    <span class="ot-chip <?= $colors['bg'] ?> <?= $colors['text'] ?> border <?= $colors['border'] ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $colors['dot'] ?>"></span>
                                        <?= ot_type_label($o['ot_type']) ?>
                                    </span>
                                    <span class="text-xs text-zinc-400">
                                        <?= format_time_12($o['start_time']) ?> – <?= format_time_12($o['end_time']) ?>
                                    </span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-sm font-bold <?= $colors['text'] ?>"><?= number_format((float)$o['total_hours'], 1) ?>h</span>
                                    <?php if ($o['ot_pay'] > 0): ?>
                                        <span class="text-xs font-bold text-emerald-400">$<?= number_format((float)$o['ot_pay'], 2) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <?php else: ?>
                <div class="glass-strong rounded-2xl p-12 text-center max-w-lg mx-auto">
                    <div class="w-16 h-16 rounded-2xl bg-white/[0.03] flex items-center justify-center mx-auto mb-4">
                        <i class="fa-solid fa-calendar-xmark text-2xl text-zinc-600"></i>
                    </div>
                    <p class="text-zinc-400 font-medium">No data for this day</p>
                    <p class="text-xs text-zinc-500 mt-1">No attendance or overtime records found.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!-- WEEKLY VIEW -->
            <!-- ═══════════════════════════════════════════ -->
            <div x-show="mode === 'weekly'" x-transition:enter="transition-all duration-200" style="display: none;">
                <?php
                $wk_total_ot = 0;
                $wk_total_ot_pay = 0;
                $wk_present = 0;
                $wk_late = 0;
                $wk_absent = 0;
                $wk_leave = 0;
                $wk_hours = 0;
                foreach ($weekly_data as $wd) {
                    $a = $wd['attendance'];
                    if ($a) {
                        if (in_array($a['status'], ['present', 'late', 'half_day'])) $wk_present++;
                        if ($a['status'] === 'late') $wk_late++;
                        if (in_array($a['status'], ['absent', 'full_absent', 'half_absent', 'awol'])) $wk_absent++;
                        if (in_array($a['status'], ['leave', 'paid_leave', 'unpaid_leave'])) $wk_leave++;
                        if ($a['total_working_hours']) $wk_hours += (float)$a['total_working_hours'];
                    }
                    foreach ($wd['overtime'] as $o) {
                        $wk_total_ot += (float)$o['total_hours'];
                        $wk_total_ot_pay += (float)$o['ot_pay'];
                    }
                }
                ?>

                <!-- Weekly Summary Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3">
                    <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400 text-xs"><i class="fa-solid fa-calendar-check"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Present</span>
                        </div>
                        <p class="text-xl font-extrabold text-white"><?= $wk_present ?><span class="text-xs font-medium text-zinc-400"> / 7</span></p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/10 flex items-center justify-center text-amber-400 text-xs"><i class="fa-solid fa-clock"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Late</span>
                        </div>
                        <p class="text-xl font-extrabold text-white"><?= $wk_late ?></p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center text-red-400 text-xs"><i class="fa-solid fa-user-xmark"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Absent</span>
                        </div>
                        <p class="text-xl font-extrabold text-white"><?= $wk_absent ?></p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-white/[0.06]">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-400 text-xs"><i class="fa-solid fa-plane-departure"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Leave</span>
                        </div>
                        <p class="text-xl font-extrabold text-white"><?= $wk_leave ?></p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-indigo-500/20 bg-indigo-500/5">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-indigo-500/10 flex items-center justify-center text-indigo-400 text-xs"><i class="fa-solid fa-clock"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-400">Working Hrs</span>
                        </div>
                        <p class="text-xl font-extrabold text-indigo-400"><?= number_format($wk_hours, 1) ?>h</p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-orange-500/20 bg-orange-500/5">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center text-orange-400 text-xs"><i class="fa-solid fa-stopwatch"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-orange-400">OT Hours</span>
                        </div>
                        <p class="text-xl font-extrabold text-orange-400"><?= number_format($wk_total_ot, 1) ?>h</p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-emerald-500/20 bg-emerald-500/5">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400 text-xs"><i class="fa-solid fa-dollar-sign"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-400">OT Pay</span>
                        </div>
                        <p class="text-xl font-extrabold text-emerald-400">$<?= number_format($wk_total_ot_pay, 2) ?></p>
                    </div>
                </div>

                <!-- Day-by-Day Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    <?php foreach ($weekly_data as $date => $wd):
                        $a = $wd['attendance'];
                        $ots = $wd['overtime'];
                        $status_badge = $a ? get_attendance_status_badge_class($a['status']) : '';
                        $status_label = $a ? get_attendance_status_label($a['status']) : 'No Record';
                        $day_ot_hours = 0;
                        $day_ot_pay = 0;
                        foreach ($ots as $o) { $day_ot_hours += (float)$o['total_hours']; $day_ot_pay += (float)$o['ot_pay']; }
                    ?>
                    <a href="?view=daily&date=<?= $date ?>" class="week-day-card block hover:no-underline <?= $wd['is_today'] ? 'is-today' : '' ?> <?= $wd['is_weekend'] && !$a ? 'is-weekend' : '' ?>">
                        <!-- Header -->
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <span class="text-sm font-extrabold text-white"><?= $wd['day_name'] ?></span>
                                <span class="text-xs text-zinc-400 ml-1.5"><?= $wd['month_short'] ?> <?= $wd['day_num'] ?></span>
                            </div>
                            <?php if ($wd['is_today']): ?>
                                <span class="text-[9px] font-bold uppercase tracking-wider text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-full">Today</span>
                            <?php endif; ?>
                        </div>

                        <!-- Status -->
                        <div class="mb-3">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold <?= $status_badge ?>">
                                <?= $status_label ?>
                            </span>
                        </div>

                        <!-- Attendance Details -->
                        <?php if ($a): ?>
                        <div class="space-y-1.5 text-xs mb-3">
                            <div class="flex justify-between">
                                <span class="text-zinc-400">Check-In</span>
                                <span class="font-semibold <?= $a['check_in'] ? 'text-emerald-400' : 'text-zinc-500' ?>"><?= $a['check_in'] ? format_time_12($a['check_in']) : '-' ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-400">Check-Out</span>
                                <span class="font-semibold <?= $a['check_out'] ? 'text-rose-400' : 'text-zinc-500' ?>"><?= $a['check_out'] ? format_time_12($a['check_out']) : '-' ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-400">Hours</span>
                                <span class="font-semibold text-indigo-400">
                                    <?php
                                    if ($a['total_working_hours']) echo number_format((float)$a['total_working_hours'], 1) . 'h';
                                    elseif ($a['check_in'] && $a['check_out']) echo number_format((strtotime($a['check_out']) - strtotime($a['check_in'])) / 3600, 1) . 'h';
                                    else echo '-';
                                    ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Overtime -->
                        <?php if (!empty($ots)): ?>
                        <div class="pt-2 border-t border-white/[0.06] space-y-2">
                            <?php foreach ($ots as $o):
                                $colors = ot_type_color($o['ot_type']);
                            ?>
                            <div class="space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="ot-chip <?= $colors['bg'] ?> <?= $colors['text'] ?> text-[10px] border <?= $colors['border'] ?>">
                                        <span class="w-1 h-1 rounded-full <?= $colors['dot'] ?>"></span>
                                        <?= ot_type_label($o['ot_type']) ?>
                                    </span>
                                    <span class="text-[10px] font-bold <?= $colors['text'] ?>"><?= number_format((float)$o['total_hours'], 1) ?>h</span>
                                </div>
                                <?php if ($o['start_time'] && $o['end_time']): ?>
                                <div class="text-[10px] text-zinc-500 pl-2">
                                    <i class="fa-solid fa-clock mr-0.5"></i>
                                    <?= format_time_12($o['start_time']) ?> – <?= format_time_12($o['end_time']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($o['ot_pay'] > 0): ?>
                                <div class="text-[10px] font-semibold text-emerald-400 pl-2">
                                    $<?= number_format((float)$o['ot_pay'], 2) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ═══════════════════════════════════════════ -->
            <!-- MONTHLY VIEW -->
            <!-- ═══════════════════════════════════════════ -->
            <div x-show="mode === 'monthly'" x-transition:enter="transition-all duration-200" style="display: none;">
                <?php $md = $monthly_data; ?>

                <!-- Monthly Summary Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    <div class="glass-strong rounded-xl p-4 border border-emerald-500/20 bg-emerald-500/5">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400 text-xs"><i class="fa-solid fa-calendar-check"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-400">Present Days</span>
                        </div>
                        <p class="text-xl font-extrabold text-white"><?= $md['present'] ?><span class="text-xs font-medium text-zinc-400"> / <?= $md['total_days_in_month'] ?></span></p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-amber-500/20 bg-amber-500/5">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/10 flex items-center justify-center text-amber-400 text-xs"><i class="fa-solid fa-clock"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-amber-400">Late Days</span>
                        </div>
                        <p class="text-xl font-extrabold text-white"><?= $md['late'] ?></p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-blue-500/20 bg-blue-500/5">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-400 text-xs"><i class="fa-solid fa-plane-departure"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-blue-400">Leave Days</span>
                        </div>
                        <p class="text-xl font-extrabold text-white"><?= $md['leave'] ?></p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-orange-500/20 bg-orange-500/5">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center text-orange-400 text-xs"><i class="fa-solid fa-stopwatch"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-orange-400">Total OT Hours</span>
                        </div>
                        <p class="text-xl font-extrabold text-orange-400"><?= number_format($md['total_ot_hours'], 1) ?>h</p>
                    </div>
                    <div class="glass-strong rounded-xl p-4 border border-emerald-500/20 bg-emerald-500/5">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400 text-xs"><i class="fa-solid fa-dollar-sign"></i></div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-400">Total OT Pay</span>
                        </div>
                        <p class="text-xl font-extrabold text-emerald-400">$<?= number_format($md['total_ot_pay'], 2) ?></p>
                    </div>
                </div>

                <!-- Additional Stats Row -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="glass-strong rounded-xl p-3 border border-white/[0.06]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Working Hours</span>
                        <p class="text-lg font-bold text-indigo-400"><?= number_format($md['total_working_hours'], 1) ?>h</p>
                    </div>
                    <div class="glass-strong rounded-xl p-3 border border-white/[0.06]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Absent Days</span>
                        <p class="text-lg font-bold text-red-400"><?= $md['absent'] ?></p>
                    </div>
                    <div class="glass-strong rounded-xl p-3 border border-white/[0.06]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Half Days</span>
                        <p class="text-lg font-bold text-purple-400"><?= $md['half_day'] ?></p>
                    </div>
                    <div class="glass-strong rounded-xl p-3 border border-white/[0.06]">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Attendance Rate</span>
                        <p class="text-lg font-bold text-emerald-400">
                            <?php
                            $working_days = $md['total_days_in_month'] - $md['weekend'] - $md['holiday'];
                            echo $working_days > 0 ? round(($md['present'] / $working_days) * 100, 1) . '%' : '-';
                            ?>
                        </p>
                    </div>
                </div>

                <!-- Monthly Day-by-Day Table -->
                <div class="glass-strong rounded-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-white/[0.06] flex items-center gap-3">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500/20 to-purple-500/20 text-indigo-400 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-list"></i>
                        </span>
                        <div>
                            <h3 class="font-bold text-white text-sm">Day-by-Day Breakdown</h3>
                            <p class="text-[10px] text-zinc-400"><?= $page_date_label ?></p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left month-table">
                            <thead class="text-zinc-500 text-[10px] uppercase tracking-wider bg-white/[0.02]">
                                <tr>
                                    <th class="px-6 py-3 font-semibold">Date</th>
                                    <th class="px-4 py-3 font-semibold">Day</th>
                                    <th class="px-4 py-3 font-semibold">Check-In</th>
                                    <th class="px-4 py-3 font-semibold">Check-Out</th>
                                    <th class="px-4 py-3 font-semibold">Hours</th>
                                    <th class="px-4 py-3 font-semibold">OT Hours</th>
                                    <th class="px-4 py-3 font-semibold">OT Time</th>
                                    <th class="px-4 py-3 font-semibold">OT Type</th>
                                    <th class="px-4 py-3 font-semibold">OT Pay</th>
                                    <th class="px-6 py-3 font-semibold text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04] text-zinc-300">
                                <?php foreach ($md['days'] as $date => $dd):
                                    $a = $dd['attendance'];
                                    $ots = $dd['overtime'];
                                    $day_ot_h = 0;
                                    $day_ot_p = 0;
                                    $day_ot_types = [];
                                    $day_ot_times = [];
                                    foreach ($ots as $o) {
                                        $day_ot_h += (float)$o['total_hours'];
                                        $day_ot_p += (float)$o['ot_pay'];
                                        if (!in_array($o['ot_type'], $day_ot_types)) $day_ot_types[] = $o['ot_type'];
                                        if ($o['start_time'] && $o['end_time']) $day_ot_times[] = format_time_12($o['start_time']) . ' – ' . format_time_12($o['end_time']);
                                    }
                                ?>
                                <tr class="hover:bg-white/[0.02] transition-colors <?= $dd['is_weekend'] && !$a ? 'opacity-50' : '' ?>">
                                    <td class="px-6 py-3 font-semibold text-white text-sm"><?= date('M d', strtotime($date)) ?></td>
                                    <td class="px-4 py-3 text-xs text-zinc-400"><?= $dd['day_name'] ?></td>
                                    <td class="px-4 py-3 font-mono text-xs <?= $a && $a['check_in'] ? 'text-emerald-400' : 'text-zinc-600' ?>">
                                        <?= $a && $a['check_in'] ? format_time_12($a['check_in']) : '-' ?>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs <?= $a && $a['check_out'] ? 'text-rose-400' : 'text-zinc-600' ?>">
                                        <?= $a && $a['check_out'] ? format_time_12($a['check_out']) : '-' ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs font-semibold text-indigo-400">
                                        <?php
                                        if ($a && $a['total_working_hours']) echo number_format((float)$a['total_working_hours'], 1) . 'h';
                                        elseif ($a && $a['check_in'] && $a['check_out']) echo number_format((strtotime($a['check_out']) - strtotime($a['check_in'])) / 3600, 1) . 'h';
                                        else echo '-';
                                        ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs font-semibold <?= $day_ot_h > 0 ? 'text-orange-400' : 'text-zinc-600' ?>">
                                        <?= $day_ot_h > 0 ? number_format($day_ot_h, 1) . 'h' : '-' ?>
                                    </td>
                                    <td class="px-4 py-3 text-[11px] text-zinc-400">
                                        <?php if (!empty($day_ot_times)): ?>
                                            <div class="space-y-0.5">
                                            <?php foreach (array_slice($day_ot_times, 0, 2) as $t): ?>
                                                <div class="font-mono"><?= $t ?></div>
                                            <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-zinc-600">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if (!empty($day_ot_types)): ?>
                                            <div class="flex flex-wrap gap-1">
                                            <?php foreach ($day_ot_types as $t):
                                                $c = ot_type_color($t);
                                            ?>
                                                <span class="ot-chip <?= $c['bg'] ?> <?= $c['text'] ?> text-[9px] border <?= $c['border'] ?>">
                                                    <?= ot_type_label($t) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-zinc-600 text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs font-semibold <?= $day_ot_p > 0 ? 'text-emerald-400' : 'text-zinc-600' ?>">
                                        <?= $day_ot_p > 0 ? '$' . number_format($day_ot_p, 2) : '-' ?>
                                    </td>
                                    <td class="px-6 py-3 text-right">
                                        <span class="inline-flex rounded-full px-3 py-1 text-[11px] font-semibold <?= $a ? get_attendance_status_badge_class($a['status']) : 'bg-zinc-500/20 text-zinc-400' ?>">
                                            <?= $a ? get_attendance_status_label($a['status']) : ($dd['is_weekend'] ? 'Weekend' : 'No Record') ?>
                                        </span>
                                        <?= $a ? get_auto_checkout_badge($a['is_auto_checkout'] ?? 0) : '' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>
