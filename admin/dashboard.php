<?php
// Simulated Database: Executive Live Telemetry Data
$systemMetrics = [
    'workforce_count' => 1248,
    'active_leaves'    => 42,
    'pending_actions'  => 18,
    'gross_payroll'    => '4.8M',
    'last_sync'        => '14m ago'
];

$departmentDensity = [
    ['name' => 'Engineering',       'percentage' => 42, 'color' => 'bg-blue-600'],
    ['name' => 'Sales & Marketing', 'percentage' => 28, 'color' => 'bg-emerald-500'],
    ['name' => 'Product',           'percentage' => 15, 'color' => 'bg-purple-500'],
    ['name' => 'Operations',        'percentage' => 15, 'color' => 'bg-amber-500'],
];

$auditLogs = [
    ['time' => '10:42 AM', 'initials' => 'JD', 'user' => 'Jane Doe',     'action' => 'Payroll Modification', 'status' => 'APPROVED', 'ref' => '#PR-8821', 'color' => 'bg-purple-100 text-purple-800'],
    ['time' => '09:15 AM', 'initials' => 'RK', 'user' => 'Robert King',   'action' => 'Leave Request (Sick)', 'status' => 'PENDING',  'ref' => '#LR-9012', 'color' => 'bg-amber-100 text-amber-800'],
    ['time' => 'Yesterday', 'initials' => 'AM', 'user' => 'Alice Moore',   'action' => 'Offboarding Initiated', 'status' => 'URGENT',   'ref' => '#OB-1102', 'color' => 'bg-rose-100 text-rose-800'],
    ['time' => 'Yesterday', 'initials' => 'SW', 'user' => 'Samuel Wright', 'action' => 'New Hire Onboarding', 'status' => 'COMPLETED', 'ref' => '#NH-4409', 'color' => 'bg-green-100 text-green-800'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Dashboard | Enterprise HR</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex">

    <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col border-r border-slate-800 sticky top-0 h-screen">
        <div class="px-6 py-5 border-b border-slate-800 flex items-center space-x-2">
            <span class="text-xl font-bold text-white tracking-tight">Enterprise HR</span>
        </div>
        <nav class="flex-1 px-4 py-4 space-y-1 text-sm font-medium">
            <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg bg-blue-600 text-white">
                <span>Dashboard</span>
            </a>
            <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
                <span>Attendance</span>
            </a>
            <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
                <span>Leave</span>
            </a>
            <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
                <span>Payroll</span>
            </a>
            <div class="pt-4 mt-4 border-t border-slate-800">
                <span class="px-3 text-xs font-bold uppercase tracking-wider text-slate-500 block mb-2">Management</span>
                <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
                    <span>Employees</span>
                </a>
                <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition">
                    <span>Reports</span>
                </a>
            </div>
        </nav>
        <div class="p-4 border-t border-slate-800 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 bg-slate-700 rounded-full flex items-center justify-center text-sm font-bold text-white">AT</div>
                <div>
                    <p class="text-xs font-semibold text-white leading-tight">Alex Thompson</p>
                    <p class="text-[11px] text-slate-500">HR Director</p>
                </div>
            </div>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0">

        <header class="bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 sticky top-0 z-10">
            <div class="w-96">
                <input type="search" placeholder="Search employees, payroll, or records..." class="w-full bg-slate-100 border-0 rounded-lg text-sm px-4 py-2 focus:ring-2 focus:ring-blue-500 text-slate-700 placeholder-slate-400">
            </div>
            <div class="flex items-center space-x-4">
                <button class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg shadow-sm transition">
                    + New Action
                </button>
            </div>
        </header>

        <main class="p-8 space-y-6 flex-1 max-w-[1600px] w-full mx-auto">

            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-slate-950">Executive Dashboard</h1>
                    <p class="text-sm text-slate-500 mt-0.5">Real-time macro-metrics and operational system feeds.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Workforce</span>
                    <div class="text-3xl font-bold text-slate-900 mt-2"><?= number_format($systemMetrics['workforce_count']) ?></div>
                    <span class="text-xs text-emerald-600 font-medium mt-1 inline-block">↑ +2.4% Across 8 locations</span>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Active Leaves</span>
                    <div class="text-3xl font-bold text-slate-900 mt-2"><?= $systemMetrics['active_leaves'] ?></div>
                    <span class="text-xs text-slate-500 font-medium mt-1 inline-block">12 starting today</span>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Pending Approvals</span>
                    <div class="text-3xl font-bold text-slate-900 mt-2"><?= $systemMetrics['pending_actions'] ?></div>
                    <span class="text-xs text-amber-600 font-medium mt-1 inline-block">Avg Response: 4.2h</span>
                </div>
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Gross Payroll</span>
                    <div class="text-3xl font-bold text-slate-900 mt-2">$<?= $systemMetrics['gross_payroll'] ?></div>
                    <span class="text-xs text-emerald-600 font-medium mt-1 inline-block">✓ Cycle open • Payout in 4d</span>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 xl:col-span-2 space-y-4">
                    <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                        <h3 class="font-bold text-slate-900 text-base">Operational Activity Feed</h3>
                        <a href="#" class="text-xs text-blue-600 font-semibold hover:underline">View Full Audit Log</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead>
                                <tr class="text-slate-400 text-xs font-bold uppercase tracking-wider border-b border-slate-100">
                                    <th class="pb-3">Timestamp</th>
                                    <th class="pb-3">Employee</th>
                                    <th class="pb-3">Action Type</th>
                                    <th class="pb-3">Reference</th>
                                    <th class="pb-3 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                                <?php foreach ($auditLogs as $log): ?>
                                    <tr>
                                        <td class="py-3.5 text-slate-400 text-xs"><?= $log['time'] ?></td>
                                        <td class="py-3.5 flex items-center space-x-2.5">
                                            <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold flex items-center justify-center"><?= $log['initials'] ?></span>
                                            <span class="text-slate-900 font-semibold"><?= $log['user'] ?></span>
                                        </td>
                                        <td class="py-3.5 text-slate-600"><?= $log['action'] ?></td>
                                        <td class="py-3.5 text-xs font-mono text-slate-400"><?= $log['ref'] ?></td>
                                        <td class="py-3.5 text-right">
                                            <span class="inline-block px-2 py-0.5 text-[11px] font-bold rounded-md <?= $log['color'] ?>">
                                                <?= $log['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-6">

                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 space-y-4">
                        <h3 class="font-bold text-slate-900 text-base border-b border-slate-100 pb-3">Department Density</h3>
                        <div class="space-y-3.5">
                            <?php foreach ($departmentDensity as $dept): ?>
                                <div class="space-y-1">
                                    <div class="flex justify-between text-xs font-semibold">
                                        <span class="text-slate-700"><?= $dept['name'] ?></span>
                                        <span class="text-slate-500"><?= $dept['percentage'] ?>%</span>
                                    </div>
                                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                        <div class="<?= $dept['color'] ?> h-full" style="width: <?= $dept['percentage'] ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-blue-900 to-slate-900 text-white rounded-xl p-5 shadow-sm space-y-3">
                        <div>
                            <h4 class="text-sm font-bold tracking-tight">Monthly Utilization Report</h4>
                            <p class="text-xs text-slate-300 mt-1">Q3 Staffing distribution and efficiency matrix reporting arrays are ready for deployment inspection.</p>
                        </div>
                        <button class="w-full bg-white/10 hover:bg-white/15 active:bg-white/20 text-white font-semibold text-xs py-2 rounded-lg border border-white/10 transition">
                            Download Utilization Metrics (PDF)
                        </button>
                    </div>

                </div>
            </div>
        </main>

        <footer class="bg-white border-t border-slate-200 px-8 py-3 text-xs text-slate-400 flex justify-between items-center mt-auto">
            <span>© 2026 ENTERPRISE HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-600">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure • Verified Sync <?= $systemMetrics['last_sync'] ?></span>
            </span>
        </footer>
    </div>
</body>

</html>