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
$unread_notifications = get_unread_count($conn, $employee_id);
$notifications = get_notifications($conn, $employee_id, 5);

// Fetch profile photo
$photo_stmt = $conn->prepare("SELECT profile_photo FROM employee WHERE id = ?");
$photo_stmt->bind_param('i', $employee_id);
$photo_stmt->execute();
$photo_result = $photo_stmt->get_result();
$employee_photo = $photo_result->fetch_assoc()['profile_photo'] ?? '';
$photo_stmt->close();

// Month selector — defaults to current month
$selected_month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', $_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}
$current_month = $selected_month;
$month_start = $current_month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

// Prev / Next month links
$prev_month = date('Y-m', strtotime($month_start . ' -1 month'));
$next_month = date('Y-m', strtotime($month_start . ' +1 month'));
$is_current_month = ($current_month === date('Y-m'));

// Attendance summary with new statuses
$summary = $conn->prepare("SELECT
    COUNT(*) as total_days,
    SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leave_days,
    SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as effective_present,
    SUM(CASE WHEN status IN ('awol', 'absent', 'full_absent', 'half_absent') THEN 1 ELSE 0 END) as absent_days,
    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$summary->bind_param('iss', $employee_id, $month_start, $month_end);
$summary->execute();
$stats = $summary->get_result()->fetch_assoc();
$summary->close();

$present_days = $stats['present_days'] ?? 0;
$total_days = $stats['total_days'] ?? 0;
$effective_present = $stats['effective_present'] ?? 0;
$absent_days = $stats['absent_days'] ?? 0;
$late_days = $stats['late_days'] ?? 0;
$present_rate = $total_days > 0 ? round(($effective_present / $total_days) * 100, 1) . '%' : '0%';
$absent_rate = $total_days > 0 ? round(($absent_days / $total_days) * 100, 1) . '%' : '0%';

// OT hours (approved)
$ot = $conn->prepare("SELECT COALESCE(SUM(total_hours), 0) as ot_hours FROM overtime_requests WHERE employee_id = ? AND ot_date BETWEEN ? AND ? AND status = 'Approved'");
$ot->bind_param('iss', $employee_id, $month_start, $month_end);
$ot->execute();
$ot_row = $ot->get_result()->fetch_assoc();
$ot_hours = $ot_row['ot_hours'];
$ot->close();

// Attendance logs
$logs = $conn->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date DESC");
$logs->bind_param('iss', $employee_id, $month_start, $month_end);
$logs->execute();
$attendance_logs = $logs->get_result()->fetch_all(MYSQLI_ASSOC);
$logs->close();

// Calendar - get all days of selected month with attendance
$calendar_data = [];
$day_count = (int)date('t', strtotime($month_start));
$today = date('Y-m-d');
for ($d = 1; $d <= $day_count; $d++) {
    $date = sprintf('%s-%02d', $current_month, $d);
    $day_of_week = date('N', strtotime($date));
    $calendar_data[$d] = [
        'day' => $d,
        'date' => $date,
        'is_today' => ($date === $today),
        'type' => ($day_of_week >= 6) ? 'weekend' : 'none',
        'status' => ($day_of_week >= 6) ? 'Weekend' : '',
        'meta' => '',
        'check_in' => '',
        'check_out' => '',
    ];
}

$cal_query = $conn->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$cal_query->bind_param('iss', $employee_id, $month_start, $month_end);
$cal_query->execute();
$cal_result = $cal_query->get_result();
while ($row = $cal_result->fetch_assoc()) {
    $d = (int)date('j', strtotime($row['attendance_date']));
    $status = $row['status'];
    $check_in = $row['check_in'] ? date('h:i A', strtotime($row['check_in'])) : '';
    $check_out = $row['check_out'] ? date('h:i A', strtotime($row['check_out'])) : '';
    $time_range = $check_in && $check_out ? $check_in . ' - ' . $check_out : ($check_in ? $check_in : '');

    if ($status == 'leave') {
        $calendar_data[$d]['type'] = 'leave';
        $calendar_data[$d]['status'] = 'Leave';
        $calendar_data[$d]['meta'] = 'Approved Leave';
    } elseif ($status == 'late') {
        $calendar_data[$d]['type'] = 'late';
        $calendar_data[$d]['status'] = 'Late';
        $calendar_data[$d]['meta'] = $time_range ? $time_range . ' (Late)' : 'Late';
        $calendar_data[$d]['check_in'] = $check_in;
        $calendar_data[$d]['check_out'] = $check_out;
    } elseif ($status == 'awol') {
        $calendar_data[$d]['type'] = 'absent';
        $calendar_data[$d]['status'] = 'Absent';
        $calendar_data[$d]['meta'] = 'AWOL - No attendance recorded';
    } elseif ($status == 'public_holiday') {
        $calendar_data[$d]['type'] = 'holiday';
        $calendar_data[$d]['status'] = 'Holiday';
        $calendar_data[$d]['meta'] = 'Public Holiday';
    } elseif ($status == 'weekend') {
        $calendar_data[$d]['type'] = 'weekend';
        $calendar_data[$d]['status'] = 'Weekend';
        $calendar_data[$d]['meta'] = 'Non-working day';
    } elseif ($status == 'half_absent') {
        $calendar_data[$d]['type'] = 'half_day';
        $calendar_data[$d]['status'] = 'Half Day';
        $calendar_data[$d]['meta'] = $time_range ? $time_range . ' (Half Day)' : 'Half Day';
        $calendar_data[$d]['check_in'] = $check_in;
        $calendar_data[$d]['check_out'] = $check_out;
    } elseif ($status == 'full_absent') {
        $calendar_data[$d]['type'] = 'absent';
        $calendar_data[$d]['status'] = 'Absent';
        $calendar_data[$d]['meta'] = $time_range ? $time_range . ' (Full-Day Absent)' : 'Full-Day Absent';
    } elseif ($row['check_in'] && $row['check_out']) {
        $calendar_data[$d]['type'] = 'present';
        $calendar_data[$d]['status'] = 'Present';
        $calendar_data[$d]['meta'] = $time_range;
        $calendar_data[$d]['check_in'] = $check_in;
        $calendar_data[$d]['check_out'] = $check_out;
    } elseif ($row['check_in']) {
        $calendar_data[$d]['type'] = 'present';
        $calendar_data[$d]['status'] = 'Present';
        $calendar_data[$d]['meta'] = 'Checked in: ' . $check_in;
        $calendar_data[$d]['check_in'] = $check_in;
    }
}
$cal_query->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Attendance Records</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased emp-page-wrapper">
    <?php $use_sidebar = true; ?>
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php $page_title = "Attendance Records"; $page_subtitle = date('l, F j, Y'); include "../includes/topbar.php"; ?>
        <main class="p-4 sm:p-6 lg:p-8 space-y-6 flex-1 page-content w-full">

            <!-- ═══ Month Selector ═══ -->
            <div class="glass-strong rounded-2xl p-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-xl shadow-lg shadow-indigo-500/25">
                            <i class="fa-solid fa-calendar-days"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-extrabold text-white tracking-tight"><?php echo date('F Y', strtotime($month_start)); ?></h3>
                            <p class="text-[11px] text-zinc-400 mt-0.5">
                                <?php if ($is_current_month): ?>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                        <span class="text-emerald-400 font-medium">Current Month</span>
                                    </span>
                                <?php else: ?>
                                    <span class="text-zinc-500">Viewing historical month</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <a href="?month=<?= $prev_month ?>" class="w-10 h-10 glass rounded-xl flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all group" title="Previous Month">
                            <i class="fa-solid fa-chevron-left text-sm text-zinc-400 group-hover:text-indigo-400 transition-colors"></i>
                        </a>

                        <input type="month" value="<?= $current_month ?>" id="monthPicker"
                            class="w-48 h-10 glass rounded-xl border border-white/[0.08] text-sm text-white text-center font-semibold cursor-pointer focus:outline-none focus:border-indigo-500/50 focus:ring-2 focus:ring-indigo-500/20 transition-all"
                            onchange="window.location.href='?month=' + this.value">

                        <?php if (!$is_current_month): ?>
                            <a href="?month=<?= $next_month ?>" class="w-10 h-10 glass rounded-xl flex items-center justify-center border border-white/[0.06] hover:border-indigo-500/40 hover:bg-indigo-500/10 transition-all group" title="Next Month">
                                <i class="fa-solid fa-chevron-right text-sm text-zinc-400 group-hover:text-indigo-400 transition-colors"></i>
                            </a>
                        <?php else: ?>
                            <div class="w-10 h-10 glass rounded-xl flex items-center justify-center border border-white/[0.04] opacity-25 cursor-not-allowed">
                                <i class="fa-solid fa-chevron-right text-sm text-zinc-500"></i>
                            </div>
                        <?php endif; ?>

                        <?php if (!$is_current_month): ?>
                            <a href="?" class="h-10 px-5 glass rounded-xl flex items-center justify-center border border-white/[0.06] hover:border-emerald-500/40 hover:bg-emerald-500/10 transition-all group gap-2 ml-1" title="Go to current month">
                                <i class="fa-solid fa-crosshairs text-[10px] text-zinc-400 group-hover:text-emerald-400 transition-colors"></i>
                                <span class="text-xs font-semibold text-zinc-400 group-hover:text-emerald-400 transition-colors hidden sm:inline">Today</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ═══ Summary Stats ═══ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-cyan-500/20 text-blue-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-calendar-check"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Present Days</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?= $effective_present ?><span class="text-sm font-medium text-zinc-400">/ <?= date('t', strtotime($month_start)) ?> Days</span></div>
                                <span class="text-xs text-blue-400 font-medium">Includes Late</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-purple-500/20 to-pink-500/20 text-purple-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-clock"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">OT Hours (Month)</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?= $ot_hours ?></div>
                                <span class="text-xs text-purple-400 font-medium">Approved</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-plane-departure"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Leave Days</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?= $stats['leave_days'] ?? 0 ?></div>
                                <span class="text-xs text-emerald-400 font-medium">This Month</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500/20 to-yellow-500/20 text-amber-400 flex items-center justify-center text-lg group-hover:scale-110 transition-transform"><i class="fa-solid fa-chart-simple"></i></div>
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Present Rate</span>
                                <div class="text-2xl font-bold text-white mt-0.5"><?= $present_rate ?></div>
                                <span class="text-xs text-amber-400 font-medium">Attendance Rate</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Calendar Styles ═══ -->
            <style>
                .cal-day {
                    position: relative;
                    aspect-ratio: 1 / 1;
                    border-radius: 10px;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    padding: 3px 2px;
                    cursor: default;
                    transition: box-shadow 0.3s ease, border-color 0.3s ease, background 0.3s ease;
                    border: 1.5px solid transparent;
                    overflow: hidden;
                    text-align: center;
                }
                .cal-day:hover {
                    z-index: 10;
                    overflow: visible;
                }
                .cal-day .day-num {
                    font-size: 12px;
                    font-weight: 700;
                    line-height: 1;
                    position: relative;
                    z-index: 1;
                }
                .cal-day .day-label {
                    font-size: 7px;
                    font-weight: 600;
                    letter-spacing: 0.02em;
                    line-height: 1.1;
                    margin-top: 2px;
                    position: relative;
                    z-index: 1;
                    opacity: 0.9;
                    max-width: 100%;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .cal-day .status-dot {
                    width: 4px;
                    height: 4px;
                    border-radius: 50%;
                    position: absolute;
                    top: 4px;
                    right: 4px;
                    z-index: 1;
                }

                /* ── Status Colors ── */
                .cal-day.status-present {
                    background: linear-gradient(135deg, rgba(16,185,129,0.18), rgba(5,150,105,0.10));
                    border-color: rgba(16,185,129,0.30);
                    color: #34D399;
                }
                .cal-day.status-present .day-label { color: #6EE7B7; }
                .cal-day.status-present .status-dot { background: #34D399; box-shadow: 0 0 5px rgba(16,185,129,0.5); }
                .cal-day.status-present:hover { background: linear-gradient(135deg, rgba(16,185,129,0.25), rgba(5,150,105,0.15)); box-shadow: 0 0 0 2px rgba(16,185,129,0.25), 0 4px 16px rgba(16,185,129,0.18); }
                :root:not(.dark) .cal-day.status-present { background: linear-gradient(135deg, rgba(22,163,74,0.12), rgba(21,128,61,0.06)); border-color: rgba(22,163,74,0.25); color: #16A34A; }
                :root:not(.dark) .cal-day.status-present .day-label { color: #15803D; }
                :root:not(.dark) .cal-day.status-present .status-dot { background: #22C55E; }

                .cal-day.status-absent {
                    background: linear-gradient(135deg, rgba(239,68,68,0.18), rgba(220,38,38,0.10));
                    border-color: rgba(239,68,68,0.30);
                    color: #F87171;
                }
                .cal-day.status-absent .day-label { color: #FCA5A5; }
                .cal-day.status-absent .status-dot { background: #F87171; box-shadow: 0 0 5px rgba(239,68,68,0.5); }
                .cal-day.status-absent:hover { background: linear-gradient(135deg, rgba(239,68,68,0.25), rgba(220,38,38,0.15)); box-shadow: 0 0 0 2px rgba(239,68,68,0.25), 0 4px 16px rgba(239,68,68,0.18); }
                :root:not(.dark) .cal-day.status-absent { background: linear-gradient(135deg, rgba(220,38,38,0.12), rgba(185,28,28,0.06)); border-color: rgba(220,38,38,0.25); color: #DC2626; }
                :root:not(.dark) .cal-day.status-absent .day-label { color: #B91C1C; }
                :root:not(.dark) .cal-day.status-absent .status-dot { background: #EF4444; }

                .cal-day.status-late {
                    background: linear-gradient(135deg, rgba(245,158,11,0.18), rgba(217,119,6,0.10));
                    border-color: rgba(245,158,11,0.30);
                    color: #FBBF24;
                }
                .cal-day.status-late .day-label { color: #FDE68A; }
                .cal-day.status-late .status-dot { background: #FBBF24; box-shadow: 0 0 5px rgba(245,158,11,0.5); }
                .cal-day.status-late:hover { background: linear-gradient(135deg, rgba(245,158,11,0.25), rgba(217,119,6,0.15)); box-shadow: 0 0 0 2px rgba(245,158,11,0.25), 0 4px 16px rgba(245,158,11,0.18); }
                :root:not(.dark) .cal-day.status-late { background: linear-gradient(135deg, rgba(217,119,6,0.12), rgba(180,83,9,0.06)); border-color: rgba(217,119,6,0.25); color: #D97706; }
                :root:not(.dark) .cal-day.status-late .day-label { color: #B45309; }
                :root:not(.dark) .cal-day.status-late .status-dot { background: #F59E0B; }

                .cal-day.status-leave {
                    background: linear-gradient(135deg, rgba(59,130,246,0.18), rgba(37,99,235,0.10));
                    border-color: rgba(59,130,246,0.30);
                    color: #60A5FA;
                }
                .cal-day.status-leave .day-label { color: #93C5FD; }
                .cal-day.status-leave .status-dot { background: #60A5FA; box-shadow: 0 0 5px rgba(59,130,246,0.5); }
                .cal-day.status-leave:hover { background: linear-gradient(135deg, rgba(59,130,246,0.25), rgba(37,99,235,0.15)); box-shadow: 0 0 0 2px rgba(59,130,246,0.25), 0 4px 16px rgba(59,130,246,0.18); }
                :root:not(.dark) .cal-day.status-leave { background: linear-gradient(135deg, rgba(37,99,235,0.12), rgba(29,78,216,0.06)); border-color: rgba(37,99,235,0.25); color: #2563EB; }
                :root:not(.dark) .cal-day.status-leave .day-label { color: #1D4ED8; }
                :root:not(.dark) .cal-day.status-leave .status-dot { background: #3B82F6; }

                .cal-day.status-half_day {
                    background: linear-gradient(135deg, rgba(168,85,247,0.18), rgba(147,51,234,0.10));
                    border-color: rgba(168,85,247,0.30);
                    color: #C084FC;
                }
                .cal-day.status-half_day .day-label { color: #D8B4FE; }
                .cal-day.status-half_day .status-dot { background: #C084FC; box-shadow: 0 0 5px rgba(168,85,247,0.5); }
                .cal-day.status-half_day:hover { background: linear-gradient(135deg, rgba(168,85,247,0.25), rgba(147,51,234,0.15)); box-shadow: 0 0 0 2px rgba(168,85,247,0.25), 0 4px 16px rgba(168,85,247,0.18); }
                :root:not(.dark) .cal-day.status-half_day { background: linear-gradient(135deg, rgba(147,51,234,0.12), rgba(124,58,237,0.06)); border-color: rgba(147,51,234,0.25); color: #7C3AED; }
                :root:not(.dark) .cal-day.status-half_day .day-label { color: #6D28D9; }
                :root:not(.dark) .cal-day.status-half_day .status-dot { background: #A855F7; }

                .cal-day.status-holiday {
                    background: linear-gradient(135deg, rgba(156,163,175,0.14), rgba(107,114,128,0.08));
                    border-color: rgba(156,163,175,0.25);
                    color: #9CA3AF;
                }
                .cal-day.status-holiday .day-label { color: #9CA3AF; }
                .cal-day.status-holiday .status-dot { background: #9CA3AF; box-shadow: 0 0 5px rgba(156,163,175,0.4); }
                .cal-day.status-holiday:hover { background: linear-gradient(135deg, rgba(156,163,175,0.20), rgba(107,114,128,0.12)); box-shadow: 0 0 0 2px rgba(156,163,175,0.20), 0 4px 16px rgba(156,163,175,0.12); }
                :root:not(.dark) .cal-day.status-holiday { background: linear-gradient(135deg, rgba(107,114,128,0.10), rgba(75,85,99,0.05)); border-color: rgba(107,114,128,0.20); color: #6B7280; }
                :root:not(.dark) .cal-day.status-holiday .day-label { color: #6B7280; }
                :root:not(.dark) .cal-day.status-holiday .status-dot { background: #9CA3AF; }

                .cal-day.status-weekend { background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.06); color: #6B7280; }
                .cal-day.status-weekend .day-label { color: #6B7280; }
                .cal-day.status-weekend:hover { background: rgba(255,255,255,0.06); box-shadow: 0 0 0 2px rgba(255,255,255,0.06), 0 4px 12px rgba(0,0,0,0.06); }
                :root:not(.dark) .cal-day.status-weekend { background: rgba(148,163,184,0.06); border-color: rgba(148,163,184,0.15); color: #94A3B8; }
                :root:not(.dark) .cal-day.status-weekend .day-label { color: #94A3B8; }

                .cal-day.status-none { background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.05); color: #6B7280; }
                :root:not(.dark) .cal-day.status-none { background: rgba(148,163,184,0.04); border-color: rgba(148,163,184,0.12); color: #94A3B8; }

                /* Today */
                .cal-day.is-today { box-shadow: 0 0 0 2px rgba(99,102,241,0.6), 0 0 12px rgba(99,102,241,0.15); border-color: rgba(99,102,241,0.7) !important; }
                :root:not(.dark) .cal-day.is-today { box-shadow: 0 0 0 2px rgba(79,70,229,0.45), 0 0 10px rgba(79,70,229,0.08); border-color: rgba(79,70,229,0.6) !important; }
                .cal-day.is-today .day-num {
                    background: linear-gradient(135deg, #6366F1, #8B5CF6);
                    color: #fff;
                    width: 22px;
                    height: 22px;
                    border-radius: 7px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 11px;
                    box-shadow: 0 2px 8px rgba(99,102,241,0.35);
                }
                :root:not(.dark) .cal-day.is-today .day-num { background: linear-gradient(135deg, #4F46E5, #7C3AED); box-shadow: 0 2px 6px rgba(79,70,229,0.25); }

                /* Tooltip */
                .cal-tooltip {
                    position: absolute;
                    bottom: calc(100% + 10px);
                    left: 50%;
                    transform: translateX(-50%) translateY(6px);
                    opacity: 0;
                    pointer-events: none;
                    transition: opacity 0.3s ease, transform 0.3s ease;
                    z-index: 50;
                    min-width: 200px;
                    max-width: 260px;
                    padding: 12px 14px;
                    border-radius: 12px;
                    font-size: 11px;
                    line-height: 1.5;
                    text-align: left;
                    white-space: normal;
                    word-wrap: break-word;
                    backdrop-filter: blur(16px);
                    border: 1px solid rgba(255,255,255,0.12);
                    background: rgba(15,23,42,0.95);
                    color: #E2E8F0;
                    box-shadow: 0 12px 32px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.05);
                }
                :root:not(.dark) .cal-tooltip { background: rgba(255,255,255,0.97); border-color: rgba(30,58,138,0.12); color: #334155; box-shadow: 0 12px 32px rgba(15,23,42,0.12), 0 0 0 1px rgba(0,0,0,0.04); }
                .cal-day:hover .cal-tooltip { opacity: 1; transform: translateX(-50%) translateY(0); }
                .cal-day.tt-left:hover .cal-tooltip { left: auto; right: -4px; transform: translateX(0) translateY(0); }
                .cal-day.tt-right:hover .cal-tooltip { left: -4px; right: auto; transform: translateX(0) translateY(0); }
                .cal-tooltip .tt-title { font-weight: 700; font-size: 12px; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
                .cal-tooltip .tt-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
                .cal-tooltip .tt-status { font-weight: 600; font-size: 12px; margin-top: 4px; }
                .cal-tooltip .tt-row { display: flex; justify-content: space-between; gap: 12px; color: #94A3B8; }
                :root:not(.dark) .cal-tooltip .tt-row { color: #64748B; }
                .cal-tooltip .tt-row span:last-child { color: #CBD5E1; font-weight: 600; }
                :root:not(.dark) .cal-tooltip .tt-row span:last-child { color: #1E293B; }

                /* Legend */
                .legend-item { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 500; color: #94A3B8; }
                :root:not(.dark) .legend-item { color: #64748B; }
                .legend-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; transition: transform 0.2s; }
                .legend-item:hover .legend-dot { transform: scale(1.25); }
            </style>

            <!-- ═══ Side-by-side: Table + Calendar ═══ -->
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6 items-start">

                <!-- Attendance History Table -->
                <div class="glass-strong rounded-2xl overflow-hidden" x-data="{ filter: 'all' }">
                    <div class="px-6 py-4 border-b border-white/[0.06]">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500/20 to-cyan-500/20 text-blue-400 flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </span>
                                <div>
                                    <h3 class="font-bold text-white text-sm">Attendance History</h3>
                                    <p class="text-[10px] text-zinc-400"><?php echo date('F Y', strtotime($month_start)); ?> records</p>
                                </div>
                            </div>
                            <?php if (!empty($attendance_logs)): ?>
                                <span class="text-[10px] text-zinc-500 font-medium"><?= count($attendance_logs) ?> total</span>
                            <?php endif; ?>
                        </div>

                        <!-- Status Filter Tabs -->
                        <?php
                        $status_counts = [];
                        foreach ($attendance_logs as $log) {
                            $s = $log['status'];
                            $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
                        }
                        ?>
                        <div class="flex flex-wrap gap-1.5">
                            <button @click="filter = 'all'" class="filter-tab" :class="filter === 'all' ? 'filter-tab-active' : ''">
                                All <span class="filter-count"><?= count($attendance_logs) ?></span>
                            </button>
                            <?php
                            $filter_statuses = [
                                'present' => ['label' => 'Present', 'color' => 'emerald'],
                                'late' => ['label' => 'Late', 'color' => 'amber'],
                                'leave' => ['label' => 'Leave', 'color' => 'blue'],
                                'awol' => ['label' => 'Absent', 'color' => 'red'],
                                'absent' => ['label' => 'Absent', 'color' => 'red'],
                                'full_absent' => ['label' => 'Absent', 'color' => 'red'],
                                'half_absent' => ['label' => 'Half Day', 'color' => 'purple'],
                                'public_holiday' => ['label' => 'Holiday', 'color' => 'gray'],
                                'weekend' => ['label' => 'Weekend', 'color' => 'gray'],
                            ];
                            $shown_filters = [];
                            foreach ($filter_statuses as $fkey => $finfo) {
                                if (isset($status_counts[$fkey]) && $status_counts[$fkey] > 0 && !in_array($finfo['label'], array_column($shown_filters, 'label'))) {
                                    $shown_filters[] = ['key' => $fkey, 'label' => $finfo['label'], 'color' => $finfo['color'], 'count' => $status_counts[$fkey]];
                                }
                            }
                            foreach ($shown_filters as $sf):
                            ?>
                                <button @click="filter = '<?= $sf['key'] ?>'" class="filter-tab" :class="filter === '<?= $sf['key'] ?>' ? 'filter-tab-active filter-<?= $sf['color'] ?>' : ''">
                                    <?= $sf['label'] ?> <span class="filter-count"><?= $sf['count'] ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <style>
                        .filter-tab {
                            display: inline-flex;
                            align-items: center;
                            gap: 5px;
                            padding: 4px 10px;
                            border-radius: 8px;
                            font-size: 11px;
                            font-weight: 600;
                            color: #94A3B8;
                            background: rgba(255, 255, 255, 0.03);
                            border: 1px solid rgba(255, 255, 255, 0.06);
                            cursor: pointer;
                            transition: all 0.2s ease;
                            white-space: nowrap;
                        }

                        :root:not(.dark) .filter-tab {
                            color: #64748B;
                            background: rgba(0, 0, 0, 0.02);
                            border-color: rgba(0, 0, 0, 0.08);
                        }

                        .filter-tab:hover {
                            background: rgba(255, 255, 255, 0.06);
                            color: #E2E8F0;
                        }

                        :root:not(.dark) .filter-tab:hover {
                            background: rgba(0, 0, 0, 0.04);
                            color: #334155;
                        }

                        .filter-tab-active {
                            color: #fff !important;
                        }

                        .filter-tab-active .filter-count {
                            opacity: 0.8;
                        }

                        .filter-tab-active.filter-emerald {
                            background: rgba(16, 185, 129, 0.2);
                            border-color: rgba(16, 185, 129, 0.3);
                            color: #34D399;
                        }

                        .filter-tab-active.filter-red {
                            background: rgba(239, 68, 68, 0.2);
                            border-color: rgba(239, 68, 68, 0.3);
                            color: #F87171;
                        }

                        .filter-tab-active.filter-amber {
                            background: rgba(245, 158, 11, 0.2);
                            border-color: rgba(245, 158, 11, 0.3);
                            color: #FBBF24;
                        }

                        .filter-tab-active.filter-blue {
                            background: rgba(59, 130, 246, 0.2);
                            border-color: rgba(59, 130, 246, 0.3);
                            color: #60A5FA;
                        }

                        .filter-tab-active.filter-purple {
                            background: rgba(168, 85, 247, 0.2);
                            border-color: rgba(168, 85, 247, 0.3);
                            color: #C084FC;
                        }

                        .filter-tab-active.filter-gray {
                            background: rgba(156, 163, 175, 0.15);
                            border-color: rgba(156, 163, 175, 0.25);
                            color: #9CA3AF;
                        }

                        .filter-tab-active:not(.filter-emerald):not(.filter-red):not(.filter-amber):not(.filter-blue):not(.filter-purple):not(.filter-gray) {
                            background: rgba(99, 102, 241, 0.2);
                            border-color: rgba(99, 102, 241, 0.3);
                            color: #818CF8;
                        }

                        .filter-count {
                            font-size: 10px;
                            padding: 1px 5px;
                            border-radius: 5px;
                            background: rgba(255, 255, 255, 0.06);
                            font-weight: 700;
                        }

                        :root:not(.dark) .filter-count {
                            background: rgba(0, 0, 0, 0.06);
                        }

                        .filter-tab-active .filter-count {
                            background: rgba(255, 255, 255, 0.15);
                        }
                    </style>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-[10px] uppercase tracking-wider bg-white/[0.02]">
                                <tr>
                                    <th class="px-6 py-3 font-semibold">Date</th>
                                    <th class="px-4 py-3 font-semibold">Log In</th>
                                    <th class="px-4 py-3 font-semibold">Log Out</th>
                                    <th class="px-4 py-3 font-semibold">Total Work</th>
                                    <th class="px-6 py-3 font-semibold text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04] text-zinc-300">
                                <?php foreach ($attendance_logs as $log):
                                    $total = '';
                                    if ($log['check_in'] && $log['check_out']) {
                                        $diff = strtotime($log['check_out']) - strtotime($log['check_in']);
                                        $total = gmdate('H:i', $diff);
                                    }
                                ?>
                                    <tr class="hover:bg-white/[0.02] transition-colors"
                                        x-show="filter === 'all' || filter === '<?= $log['status'] ?>'">
                                        <td class="px-6 py-3.5 font-semibold text-white text-sm"><?= date('M d, Y', strtotime($log['attendance_date'])) ?></td>
                                        <td class="px-4 py-3.5 font-mono text-xs text-zinc-400"><?= $log['check_in'] ? date('h:i:s A', strtotime($log['check_in'])) : '<span class="text-zinc-600">-</span>' ?></td>
                                        <td class="px-4 py-3.5 font-mono text-xs text-zinc-400"><?= $log['check_out'] ? date('h:i:s A', strtotime($log['check_out'])) : '<span class="text-zinc-600">-</span>' ?></td>
                                        <td class="px-4 py-3.5 font-semibold text-sm"><?= $total ?: '<span class="text-zinc-600">-</span>' ?></td>
                                        <td class="px-6 py-3.5 text-right">
                                            <span class="inline-flex rounded-full px-3 py-1 text-[11px] font-semibold <?= get_attendance_status_badge_class($log['status']) ?>">
                                                <?= get_attendance_status_label($log['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($attendance_logs)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-16 text-center">
                                            <div class="flex flex-col items-center gap-3">
                                                <div class="w-12 h-12 rounded-2xl bg-white/[0.03] flex items-center justify-center">
                                                    <i class="fa-solid fa-calendar-xmark text-xl text-zinc-600"></i>
                                                </div>
                                                <p class="text-sm text-zinc-400 font-medium">No attendance records</p>
                                                <p class="text-xs text-zinc-500">No data found for this month.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Small Calendar Sidebar -->
                <div class="glass-strong rounded-2xl p-4 relative" x-data="{ legendExpanded: true }">
                    <div class="flex items-center justify-between border-b border-white/[0.06] pb-3 mb-3">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-500/20 to-purple-500/20 text-indigo-400 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-calendar-days"></i>
                              </span>
                            <div>
                                <h3 class="font-bold text-white text-sm">Calendar</h3>
                                <p class="text-[10px] text-zinc-400"><?php echo date('F Y', strtotime($month_start)); ?></p>
                            </div>
                        </div>
                        <button @click="legendExpanded = !legendExpanded" class="w-7 h-7 glass rounded-lg flex items-center justify-center border border-white/[0.06] hover:border-white/10 transition" :class="legendExpanded ? 'bg-white/[0.04]' : ''">
                            <i class="fa-solid fa-circle-info text-[9px] text-indigo-400"></i>
                        </button>
                    </div>

                    <div x-show="legendExpanded" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="mb-3 p-2.5 rounded-lg border border-white/[0.06] bg-white/[0.02]">
                        <div class="grid grid-cols-2 gap-x-3 gap-y-1.5">
                            <div class="legend-item"><span class="legend-dot" style="background: linear-gradient(135deg, #10B981, #059669);"></span>Present</div>
                            <div class="legend-item"><span class="legend-dot" style="background: linear-gradient(135deg, #EF4444, #DC2626);"></span>Absent</div>
                            <div class="legend-item"><span class="legend-dot" style="background: linear-gradient(135deg, #F59E0B, #D97706);"></span>Late</div>
                            <div class="legend-item"><span class="legend-dot" style="background: linear-gradient(135deg, #3B82F6, #2563EB);"></span>Leave</div>
                            <div class="legend-item"><span class="legend-dot" style="background: linear-gradient(135deg, #A855F7, #7C3AED);"></span>Half Day</div>
                            <div class="legend-item"><span class="legend-dot" style="background: linear-gradient(135deg, #9CA3AF, #6B7280);"></span>Holiday</div>
                        </div>
                    </div>

                    <!-- Weekday Headers -->
                    <div class="grid grid-cols-7 gap-1 text-center text-[9px] font-semibold text-zinc-500 mb-1">
                        <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span>
                        <span class="text-amber-500/80">S</span><span class="text-amber-500/80">S</span>
                    </div>

                    <!-- Calendar Grid -->
                    <div class="grid grid-cols-7 gap-1">
                        <?php
                        $first_day_of_week = date('N', strtotime($current_month . '-01')) - 1;
                        $col = $first_day_of_week;
                        for ($i = 0; $i < $first_day_of_week; $i++):
                        ?>
                            <div></div>
                        <?php endfor; ?>
                        <?php foreach ($calendar_data as $day):
                            $col++;
                            if ($col > 7) $col = 1;
                            $type_class = 'status-' . $day['type'];
                            $today_class = $day['is_today'] ? ' is-today' : '';
                            $tt_color_map = [
                                'present' => '#34D399', 'absent' => '#F87171', 'late' => '#FBBF24',
                                'leave' => '#60A5FA', 'half_day' => '#C084FC', 'holiday' => '#9CA3AF',
                                'weekend' => '#6B7280', 'none' => '#6B7280',
                            ];
                            $tt_color = $tt_color_map[$day['type']] ?? '#6B7280';
                        ?>
                            <div class="cal-day <?= $type_class ?><?= $today_class ?>">
                                <span class="status-dot"></span>
                                <span class="day-num"><?= $day['day'] ?></span>
                                <?php if (!empty($day['status'])): ?>
                                    <span class="day-label"><?= htmlspecialchars($day['status']) ?></span>
                                <?php endif; ?>
                                <div class="cal-tooltip">
                                    <div class="tt-title">
                                        <span class="tt-dot" style="background: <?= $tt_color ?>; box-shadow: 0 0 6px <?= $tt_color ?>66;"></span>
                                        <?= date('l, M d', strtotime($day['date'])) ?>
                                    </div>
                                    <?php if (!empty($day['status'])): ?>
                                        <div class="tt-status" style="color: <?= $tt_color ?>;"><?= htmlspecialchars($day['status']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($day['meta'])): ?>
                                        <div style="color: #94A3B8; font-size: 11px; margin-top: 2px;"><?= htmlspecialchars($day['meta']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($day['check_in']): ?>
                                        <div class="tt-row mt-2"><span>Check In</span><span><?= $day['check_in'] ?></span></div>
                                    <?php endif; ?>
                                    <?php if ($day['check_out']): ?>
                                        <div class="tt-row"><span>Check Out</span><span><?= $day['check_out'] ?></span></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <?php include "../includes/employee_bottom_nav.php"; ?>
</body>

</html>