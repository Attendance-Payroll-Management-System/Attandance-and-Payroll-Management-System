<?php
// Simulated Database Structure: Self-contained Employee Attendance Metrics
$attendanceSummary = [
    'total_working_days' => 22,
    'expected_days'      => 22,
    'ot_hours'           => 14.5,
    'estimated_ot_pay'   => 420.50,
    'avg_check_in'       => '08:52 AM',
    'present_rate'       => '98.2%'
];

// Activity logs for the data table
$attendanceLogs = [
    ['date' => 'Oct 10, 2023', 'in' => '08:55:22', 'out' => '18:30:45', 'total' => '09:35', 'ot' => '+01:35', 'status' => 'Approved'],
    ['date' => 'Oct 09, 2023', 'in' => '08:50:11', 'out' => '18:10:05', 'total' => '09:20', 'ot' => '+01:20', 'status' => 'Approved'],
    ['date' => 'Oct 06, 2023', 'in' => '08:45:55', 'out' => '17:50:12', 'total' => '09:04', 'ot' => '+01:04', 'status' => 'Pending'],
    ['date' => 'Oct 05, 2023', 'in' => '-',        'out' => '-',        'total' => '00:00', 'ot' => '00:00',  'status' => 'Sick Leave'],
    ['date' => 'Oct 04, 2023', 'in' => '09:02:40', 'out' => '18:00:22', 'total' => '08:58', 'ot' => '00:00',  'status' => 'Approved'],
];

// Calendar Matrix Mocking (October 2023 layout snippet)
$calendarDays = [
    ['day' => 1,  'type' => 'weekend', 'meta' => ''],
    ['day' => 2,  'type' => 'present', 'meta' => '08:50 - 18:05'],
    ['day' => 3,  'type' => 'present', 'meta' => '08:55 - 18:15'],
    ['day' => 4,  'type' => 'present', 'meta' => '09:02 - 18:00'],
    ['day' => 5,  'type' => 'leave',   'meta' => 'Sick Leave'],
    ['day' => 6,  'type' => 'present', 'meta' => '08:45 - 17:50'],
    ['day' => 7,  'type' => 'weekend', 'meta' => ''],
    ['day' => 8,  'type' => 'weekend', 'meta' => ''],
    ['day' => 9,  'type' => 'present', 'meta' => '08:50 - 18:10'],
    ['day' => 10, 'type' => 'present', 'meta' => '08:55 - 18:30'],
    ['day' => 11, 'type' => 'active',  'meta' => '08:48 - Active'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance & OT | Enterprise HR</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-gradient-to-br from-sky-50 via-slate-100 to-slate-200 text-slate-900 font-sans antialiased">

    <header class="sticky top-0 z-20 bg-white/95 backdrop-blur-xl border-b border-slate-200/70 shadow-sm">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-4">
            <div class="flex items-center gap-4">
                <div class="rounded-2xl bg-blue-600 px-4 py-2 text-white shadow-lg shadow-blue-500/20">
                    <span class="text-sm font-semibold">HR</span>
                </div>
                <div>
                    <p class="text-lg font-semibold text-slate-900">Enterprise HR</p>
                    <p class="text-sm text-slate-500">Attendance & Overtime Dashboard</p>
                </div>
            </div>
            <nav class="hidden md:flex items-center gap-2 text-sm font-medium text-slate-600">
                <a href="#" class="rounded-2xl px-3 py-2 transition hover:bg-slate-100">Dashboard</a>
                <a href="#" class="rounded-2xl bg-blue-600 px-3 py-2 text-white shadow-sm shadow-blue-500/20">Attendance</a>
                <a href="#" class="rounded-2xl px-3 py-2 transition hover:bg-slate-100">Leave</a>
                <a href="#" class="rounded-2xl px-3 py-2 transition hover:bg-slate-100">Payroll</a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto p-6 space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-[28px] border border-blue-200/70 bg-blue-600/10 p-5 shadow-sm shadow-blue-500/10 backdrop-blur-sm">
                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-sky-600">Total Working Days</span>
                <div class="mt-3 text-3xl font-semibold text-slate-900">
                    <?= $attendanceSummary['total_working_days'] ?>
                    <span class="text-sm font-medium text-slate-500">/ <?= $attendanceSummary['expected_days'] ?> Expected</span>
                </div>
            </div>
            <div class="rounded-[28px] border border-slate-200/80 bg-white/90 p-5 shadow-sm shadow-slate-300/20">
                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">OT Hours (Month)</span>
                <div class="mt-3 text-3xl font-semibold text-slate-900">
                    <?= $attendanceSummary['ot_hours'] ?>
                    <span class="text-sm font-medium text-blue-600">~ $<?= number_format($attendanceSummary['estimated_ot_pay'], 2) ?></span>
                </div>
            </div>
            <div class="rounded-[28px] border border-slate-200/80 bg-white/90 p-5 shadow-sm shadow-slate-300/20">
                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">Avg Check-In</span>
                <div class="mt-3 text-3xl font-semibold text-slate-900">
                    <?= $attendanceSummary['avg_check_in'] ?>
                    <span class="text-sm font-medium text-blue-600 block">8 mins after shift start</span>
                </div>
            </div>
            <div class="rounded-[28px] border border-blue-200/70 bg-blue-600/10 p-5 shadow-sm shadow-blue-500/10 backdrop-blur-sm">
                <span class="text-xs font-semibold uppercase tracking-[0.25em] text-sky-600">Present Rate</span>
                <div class="mt-3 text-3xl font-semibold text-slate-900">
                    <?= $attendanceSummary['present_rate'] ?>
                    <span class="text-sm font-medium text-slate-500 block">Top percentile in Dept</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <div class="lg:col-span-2 rounded-[32px] border border-slate-200/80 bg-white/95 p-6 shadow-xl shadow-slate-400/5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Attendance History</h2>
                        <p class="text-sm text-slate-500">Review recent punch logs and overtime breakdowns.</p>
                    </div>
                    <button class="inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm shadow-blue-500/20 transition hover:bg-blue-700">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                            <path d="M4 3a1 1 0 011-1h10a1 1 0 011 1v12a1 1 0 01-1 1H5a1 1 0 01-1-1V3z" />
                            <path d="M8 9h4m-4 2h4m-4 2h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                        </svg>
                        Export CSV
                    </button>
                </div>

                <div class="overflow-x-auto mt-4">
                    <table class="w-full min-w-[720px] text-left border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 text-xs uppercase tracking-[0.24em]">
                                <th class="py-3 font-semibold">Date</th>
                                <th class="py-3 font-semibold">Log In</th>
                                <th class="py-3 font-semibold">Log Out</th>
                                <th class="py-3 font-semibold">Total Work</th>
                                <th class="py-3 font-semibold">Overtime</th>
                                <th class="py-3 font-semibold text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                            <?php foreach ($attendanceLogs as $log): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="py-4 font-semibold text-slate-900"><?= $log['date'] ?></td>
                                    <td class="py-4 font-mono text-slate-600"><?= $log['in'] ?></td>
                                    <td class="py-4 font-mono text-slate-600"><?= $log['out'] ?></td>
                                    <td class="py-4"><?= $log['total'] ?></td>
                                    <td class="py-4 text-blue-600 font-semibold"><?= $log['ot'] ?></td>
                                    <td class="py-4 text-right">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold 
                                            <?= $log['status'] === 'Approved' ? 'bg-blue-100 text-blue-700' : '' ?>
                                            <?= $log['status'] === 'Pending' ? 'bg-blue-50 text-blue-700' : '' ?>
                                            <?= $log['status'] === 'Sick Leave' ? 'bg-slate-100 text-slate-700' : '' ?>
                                        ">
                                            <?= $log['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-[32px] border border-slate-200/80 bg-white/95 p-6 shadow-xl shadow-slate-400/5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Activity Calendar</h2>
                        <p class="text-sm text-slate-500">Track your attendance and leave pattern.</p>
                    </div>
                    <span class="rounded-2xl bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">October 2023</span>
                </div>

                <div class="grid grid-cols-7 text-center text-xs font-semibold text-slate-500 gap-2 mt-4">
                    <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
                </div>

                <div class="grid grid-cols-7 gap-2 mt-3">
                    <?php foreach ($calendarDays as $day): ?>
                        <div class="rounded-2xl border p-3 min-h-[70px] text-left text-xs transition-all duration-150
                            <?= $day['type'] === 'present' ? 'bg-sky-50 border-sky-200 text-slate-900 hover:bg-sky-100' : '' ?>
                            <?= $day['type'] === 'leave' ? 'bg-rose-50 border-rose-200 text-rose-900' : '' ?>
                            <?= $day['type'] === 'active' ? 'bg-blue-600/10 border-blue-400 text-blue-900 font-semibold shadow-sm shadow-blue-500/10' : '' ?>
                            <?= $day['type'] === 'weekend' ? 'bg-slate-100 border-slate-200 text-slate-400' : '' ?>
                        ">
                            <span class="block text-base font-bold"><?= $day['day'] ?></span>
                            <?php if (!empty($day['meta'])): ?>
                                <span class="mt-2 block leading-tight tracking-tight text-[10px] opacity-80 truncate"><?= $day['meta'] ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </main>

    <footer class="mt-10 text-center text-sm text-slate-500">
        <p>© 2026 Enterprise HR Systems</p>
        <p class="mt-1">A beautiful blue dashboard for modern attendance tracking.</p>
    </footer>
</body>

</html>