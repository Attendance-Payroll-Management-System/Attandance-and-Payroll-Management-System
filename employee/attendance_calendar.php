<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];

set_mmt_timezone();

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)mmt_date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)mmt_date('Y');
$month_name = date('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year));
$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

// Fetch attendance records for the month
$att_stmt = $conn->prepare("SELECT attendance_date, check_in, check_out, status, total_working_hours, is_late, remarks FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date");
$att_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$att_stmt->execute();
$records = $att_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$att_stmt->close();

// Get holidays for the month
$hol_stmt = $conn->prepare("SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$hol_stmt->bind_param('ss', $month_start, $month_end);
$hol_stmt->execute();
$holidays = $hol_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hol_stmt->close();

// Get monthly summary
$summary = get_monthly_attendance_summary($conn, $employee_id, $month_start, $month_end);
$working_days = get_working_days_in_month($selected_year, $selected_month);
$effective_present = (int)$summary['present_days'] + (int)$summary['late_days'];
$attendance_rate = $working_days > 0 ? round(($effective_present / $working_days) * 100, 1) : 0;

// Build calendar data
$cal_days = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
$first_day_of_week = date('N', strtotime($month_start)); // 1=Mon, 7=Sun

$att_map = [];
foreach ($records as $r) {
    $day_num = (int)date('j', strtotime($r['attendance_date']));
    $att_map[$day_num] = $r;
}

$holiday_map = [];
foreach ($holidays as $h) {
    $day_num = (int)date('j', strtotime($h['holiday_date']));
    $holiday_map[$day_num] = $h['holiday_name'];
}

// Stats for the stats cards
$ot_stmt = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as ot_hours FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
$ot_stmt->bind_param('iss', $employee_id, $month_start, $month_end);
$ot_stmt->execute();
$ot_hours = (float)$ot_stmt->get_result()->fetch_assoc()['ot_hours'];
$ot_stmt->close();

$status_colors = [
    'present' => ['bg' => 'rgba(16,185,129,0.2)', 'border' => 'rgba(16,185,129,0.4)', 'text' => '#34D399', 'label' => 'Present'],
    'late' => ['bg' => 'rgba(245,158,11,0.2)', 'border' => 'rgba(245,158,11,0.4)', 'text' => '#FBBF24', 'label' => 'Late'],
    'half_day' => ['bg' => 'rgba(20,184,166,0.2)', 'border' => 'rgba(20,184,166,0.4)', 'text' => '#2DD4BF', 'label' => 'Half Day'],
    'paid_leave' => ['bg' => 'rgba(14,165,233,0.2)', 'border' => 'rgba(14,165,233,0.4)', 'text' => '#38BDF8', 'label' => 'Paid Leave'],
    'unpaid_leave' => ['bg' => 'rgba(251,146,60,0.2)', 'border' => 'rgba(251,146,60,0.4)', 'text' => '#FB923C', 'label' => 'Unpaid Leave'],
    'absent' => ['bg' => 'rgba(239,68,68,0.2)', 'border' => 'rgba(239,68,68,0.4)', 'text' => '#F87171', 'label' => 'Absent'],
    'awol' => ['bg' => 'rgba(239,68,68,0.2)', 'border' => 'rgba(239,68,68,0.4)', 'text' => '#F87171', 'label' => 'AWOL'],
    'full_absent' => ['bg' => 'rgba(239,68,68,0.2)', 'border' => 'rgba(239,68,68,0.4)', 'text' => '#F87171', 'label' => 'Full Absent'],
    'public_holiday' => ['bg' => 'rgba(168,85,247,0.2)', 'border' => 'rgba(168,85,247,0.4)', 'text' => '#C084FC', 'label' => 'Holiday'],
    'weekend' => ['bg' => 'rgba(255,255,255,0.04)', 'border' => 'rgba(255,255,255,0.08)', 'text' => '#6B7280', 'label' => 'Weekend'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance Calendar</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
    <style>
        .cal-cell {
            position: relative;
            min-height: 100px;
            border-radius: 10px;
            padding: 6px;
            transition: all 0.2s ease;
            cursor: pointer;
            border: 1.5px solid transparent;
        }
        .cal-cell:hover {
            transform: translateY(-2px);
            z-index: 5;
        }
        .cal-cell .day-number {
            font-size: 13px;
            font-weight: 700;
            line-height: 1;
        }
        .cal-cell .day-status {
            font-size: 8px;
            font-weight: 600;
            margin-top: 2px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cal-cell .day-hours {
            font-size: 7px;
            opacity: 0.6;
            margin-top: 1px;
        }
        .cal-cell.is-today {
            box-shadow: 0 0 0 2px rgba(99,102,241,0.6);
            border-color: rgba(99,102,241,0.7) !important;
        }
        .cal-cell.is-today .day-number {
            background: linear-gradient(135deg, #6366F1, #8B5CF6);
            color: #fff;
            width: 24px;
            height: 24px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(99,102,241,0.35);
        }
        .cal-cell.other-month { opacity: 0.3; }
        .detail-popup {
            backdrop-filter: blur(16px);
            background: rgba(15,23,42,0.97);
            border: 1px solid rgba(255,255,255,0.1);
        }
        :root:not(.dark) .detail-popup {
            background: rgba(255,255,255,0.97);
            border-color: rgba(0,0,0,0.1);
        }
    </style>
</head>
<body x-data="{ selectedDay: null, showDetail: false }" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php
        $page_title = "Attendance Calendar";
        $page_subtitle = "Visual attendance overview";
        ob_start();
        ?>
        <div class="flex items-center gap-2">
            <a href="?month=<?php echo $selected_month - 1 < 1 ? 12 : $selected_month - 1; ?>&year=<?php echo $selected_month - 1 < 1 ? $selected_year - 1 : $selected_year; ?>"
               class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all">
                <i class="fa-solid fa-chevron-left text-xs text-zinc-400"></i>
            </a>
            <a href="?month=<?php echo $selected_month + 1 > 12 ? 1 : $selected_month + 1; ?>&year=<?php echo $selected_month + 1 > 12 ? $selected_year + 1 : $selected_year; ?>"
               class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all">
                <i class="fa-solid fa-chevron-right text-xs text-zinc-400"></i>
            </a>
            <a href="?" class="w-9 h-9 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-emerald-500/40 hover:bg-emerald-500/10 transition-all" title="Today">
                <i class="fa-solid fa-crosshairs text-xs text-zinc-400"></i>
            </a>
        </div>
        <?php
        $page_actions = ob_get_clean();
        include "../includes/topbar.php";
        ?>
        <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 page-content w-full">

            <!-- Summary Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
                <div class="glass-strong rounded-xl p-3 text-center">
                    <p class="text-lg font-bold text-emerald-400"><?php echo (int)$summary['present_days']; ?></p>
                    <p class="text-[10px] text-zinc-500">Present</p>
                </div>
                <div class="glass-strong rounded-xl p-3 text-center">
                    <p class="text-lg font-bold text-amber-400"><?php echo (int)$summary['late_days']; ?></p>
                    <p class="text-[10px] text-zinc-500">Late</p>
                </div>
                <div class="glass-strong rounded-xl p-3 text-center">
                    <p class="text-lg font-bold text-teal-400"><?php echo (int)$summary['half_days']; ?></p>
                    <p class="text-[10px] text-zinc-500">Half Day</p>
                </div>
                <div class="glass-strong rounded-xl p-3 text-center">
                    <p class="text-lg font-bold text-red-400"><?php echo (int)$summary['absent_days']; ?></p>
                    <p class="text-[10px] text-zinc-500">Absent</p>
                </div>
                <div class="glass-strong rounded-xl p-3 text-center">
                    <p class="text-lg font-bold text-sky-400"><?php echo (int)$summary['paid_leave_days'] + (int)$summary['unpaid_leave_days']; ?></p>
                    <p class="text-[10px] text-zinc-500">Leave</p>
                </div>
                <div class="glass-strong rounded-xl p-3 text-center">
                    <p class="text-lg font-bold text-purple-400"><?php echo number_format($ot_hours, 1); ?></p>
                    <p class="text-[10px] text-zinc-500">OT Hours</p>
                </div>
                <div class="glass-strong rounded-xl p-3 text-center">
                    <p class="text-lg font-bold <?php echo $attendance_rate >= 80 ? 'text-emerald-400' : ($attendance_rate >= 50 ? 'text-amber-400' : 'text-red-400'); ?>"><?php echo $attendance_rate; ?>%</p>
                    <p class="text-[10px] text-zinc-500">Rate</p>
                </div>
            </div>

            <!-- Calendar Header -->
            <div class="glass-strong rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-extrabold text-white"><?php echo $month_name; ?></h2>
                    <span class="text-xs text-zinc-500 bg-white/[0.04] px-3 py-1.5 rounded-lg"><?php echo $working_days; ?> working days</span>
                </div>

                <!-- Day Headers -->
                <div class="grid grid-cols-7 gap-2 mb-2">
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day): ?>
                    <div class="text-center text-[11px] font-bold text-zinc-500 py-2 <?php echo in_array($day, ['Sat', 'Sun']) ? 'text-amber-500/60' : ''; ?>">
                        <?php echo $day; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar Grid -->
                <div class="grid grid-cols-7 gap-2">
                    <?php
                    // Padding for first day
                    for ($i = 1; $i < $first_day_of_week; $i++):
                    ?>
                        <div></div>
                    <?php endfor; ?>

                    <?php for ($d = 1; $d <= $cal_days; $d++):
                        $date_str = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $d);
                        $is_wknd = is_weekend($date_str);
                        $is_today = ($date_str === mmt_date());
                        $record = $att_map[$d] ?? null;
                        $status = $record['status'] ?? null;
                        $is_holiday = isset($holiday_map[$d]);

                        // Determine display status
                        $display_status = $status;
                        if ($is_wknd && !$status) $display_status = 'weekend';
                        if ($is_holiday && !$status) $display_status = 'public_holiday';

                        $colors = $status_colors[$display_status] ?? ['bg' => 'rgba(255,255,255,0.03)', 'border' => 'rgba(255,255,255,0.06)', 'text' => '#6B7280', 'label' => ''];

                        $has_data = $record || $is_wknd || $is_holiday;
                    ?>
                        <div class="cal-cell" style="background: <?php echo $colors['bg']; ?>; border-color: <?php echo $colors['border']; ?>;"
                             @click="selectedDay=<?php echo $d; ?>; showDetail=true"
                             x-data="{ open: false }"
                             @mouseenter="open = true" @mouseleave="open = false"
                             class="<?php echo $is_today ? 'is-today' : ''; ?>">
                            <span class="day-number" style="color: <?php echo $colors['text']; ?>"><?php echo $d; ?></span>
                            <?php if ($is_holiday && !$status): ?>
                                <div class="day-status" style="color: <?php echo $status_colors['public_holiday']['text']; ?>">Holiday</div>
                            <?php elseif ($record && $record['status']): ?>
                                <div class="day-status" style="color: <?php echo $colors['text']; ?>"><?php echo get_attendance_status_label($record['status']); ?></div>
                                <?php if ($record['total_working_hours']): ?>
                                <div class="day-hours" style="color: <?php echo $colors['text']; ?>"><?php echo number_format($record['total_working_hours'], 1); ?>h</div>
                                <?php endif; ?>
                            <?php elseif ($is_wknd && !$status): ?>
                                <div class="day-status" style="color: #6B7280">Weekend</div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Legend -->
                <div class="flex flex-wrap items-center gap-3 mt-6 pt-4 border-t border-white/[0.06]">
                    <span class="text-[10px] font-semibold text-zinc-500 uppercase tracking-wider mr-1">Legend:</span>
                    <?php
                    $legend_items = [
                        ['color' => '#34D399', 'label' => 'Present'],
                        ['color' => '#FBBF24', 'label' => 'Late'],
                        ['color' => '#2DD4BF', 'label' => 'Half Day'],
                        ['color' => '#38BDF8', 'label' => 'Paid Leave'],
                        ['color' => '#FB923C', 'label' => 'Unpaid Leave'],
                        ['color' => '#F87171', 'label' => 'Absent'],
                        ['color' => '#C084FC', 'label' => 'Holiday'],
                        ['color' => '#6B7280', 'label' => 'Weekend'],
                    ];
                    foreach ($legend_items as $item):
                    ?>
                    <span class="flex items-center gap-1.5 text-[11px] text-zinc-500">
                        <span class="w-3 h-3 rounded" style="background: <?php echo $item['color']; ?>;"></span>
                        <?php echo $item['label']; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Detail Modal -->
            <div x-show="showDetail" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.outside="showDetail = false">
                <div class="detail-popup rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
                    <?php
                    // Find the selected day's record
                    $sel_day = 0;
                    ?>
                    <div class="p-5">
                        <?php
                        // We use JS to set a hidden field; but here we render all day details and use x-show
                        for ($d = 1; $d <= $cal_days; $d++):
                            $date_str = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $d);
                            $is_wknd = is_weekend($date_str);
                            $record = $att_map[$d] ?? null;
                            $status = $record['status'] ?? null;
                            $is_holiday = isset($holiday_map[$d]);
                            $display_status = $status;
                            if ($is_wknd && !$status) $display_status = 'weekend';
                            if ($is_holiday && !$status) $display_status = 'public_holiday';
                            $colors = $status_colors[$display_status] ?? ['bg' => '', 'border' => '', 'text' => '#6B7280', 'label' => 'Weekend'];
                            $day_name = date('l', strtotime($date_str));
                        ?>
                        <div x-show="selectedDay === <?php echo $d; ?>">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h3 class="text-lg font-bold text-white"><?php echo date('F j, Y', strtotime($date_str)); ?></h3>
                                    <p class="text-sm text-zinc-400"><?php echo $day_name; ?></p>
                                </div>
                                <span class="px-3 py-1.5 rounded-full text-xs font-bold" style="background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['text']; ?>; border: 1px solid <?php echo $colors['border']; ?>;">
                                    <?php echo $colors['label']; ?>
                                </span>
                            </div>

                            <?php if ($record && $record['check_in']): ?>
                            <div class="space-y-3">
                                <div class="flex justify-between items-center p-3 bg-white/[0.04] rounded-xl">
                                    <span class="text-sm text-zinc-400">Check In</span>
                                    <span class="text-sm font-semibold text-white font-mono"><?php echo date('h:i:s A', strtotime($record['check_in'])); ?></span>
                                </div>
                                <?php if ($record['check_out']): ?>
                                <div class="flex justify-between items-center p-3 bg-white/[0.04] rounded-xl">
                                    <span class="text-sm text-zinc-400">Check Out</span>
                                    <span class="text-sm font-semibold text-white font-mono"><?php echo date('h:i:s A', strtotime($record['check_out'])); ?></span>
                                </div>
                                <div class="flex justify-between items-center p-3 bg-white/[0.04] rounded-xl">
                                    <span class="text-sm text-zinc-400">Total Hours</span>
                                    <span class="text-sm font-semibold text-white"><?php echo number_format((float)($record['total_working_hours'] ?? 0), 2); ?>h</span>
                                </div>
                                <?php endif; ?>
                                <?php if ($record['is_late']): ?>
                                <div class="flex justify-between items-center p-3 bg-amber-500/10 rounded-xl">
                                    <span class="text-sm text-zinc-400">Late Check-in</span>
                                    <span class="text-sm font-semibold text-amber-400">Yes</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php elseif ($is_holiday): ?>
                            <div class="p-4 bg-purple-500/10 rounded-xl text-center">
                                <p class="text-sm font-semibold text-purple-400"><?php echo htmlspecialchars($holiday_map[$d]); ?></p>
                                <p class="text-xs text-zinc-500 mt-1">Public Holiday - Full Salary</p>
                            </div>
                            <?php elseif ($is_wknd): ?>
                            <div class="p-4 bg-white/[0.04] rounded-xl text-center">
                                <p class="text-sm text-zinc-400">Weekend - No Attendance Required</p>
                                <p class="text-xs text-zinc-500 mt-1">Full Salary Maintained</p>
                            </div>
                            <?php else: ?>
                            <div class="p-4 bg-white/[0.04] rounded-xl text-center">
                                <p class="text-sm text-zinc-400">No attendance recorded</p>
                            </div>
                            <?php endif; ?>

                            <button @click="showDetail = false" class="w-full mt-4 px-4 py-2.5 bg-white/[0.06] hover:bg-white/[0.10] text-zinc-300 rounded-xl text-sm font-semibold transition-all">
                                Close
                            </button>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>
</html>
