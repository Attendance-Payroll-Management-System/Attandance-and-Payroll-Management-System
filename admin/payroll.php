<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Control Center</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans text-gray-800 antialiased">

    <!-- App Container -->
    <div class="flex min-h-screen">

        <!-- 1. Navigation Sidebar -->
        <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col border-r border-slate-800">
            <!-- Sidebar Header -->
            <div class="p-6 border-b border-slate-800 flex items-center gap-3">
                <div class="h-8 w-8 bg-indigo-600 rounded-lg flex items-center justify-center font-bold text-white text-lg">💰</div>
                <span class="font-bold text-white text-lg tracking-wide">PayRoll Pro</span>
            </div>
            <!-- Sidebar Links -->
            <nav class="flex-1 p-4 space-y-1">
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition">
                    <span>📊</span> Dashboard
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition">
                    <span>👥</span> Employees
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition">
                    <span>📅</span> Attendance Log
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition">
                    <span>🏖️</span> Leave Requests
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-indigo-600 text-white font-medium shadow-md shadow-indigo-600/10">
                    <span>💳</span> Payroll Center
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-slate-800 transition">
                    <span>⚙️</span> Settings
                </a>
            </nav>
            <!-- User Footer -->
            <div class="p-4 border-t border-slate-800 flex items-center gap-3 text-sm text-slate-400">
                <div class="h-8 w-8 rounded-full bg-slate-700 flex items-center justify-center text-white font-bold text-xs">HR</div>
                <div>
                    <p class="font-medium text-slate-200">Admin User</p>
                    <p class="text-xs text-slate-500">hr@company.com</p>
                </div>
            </div>
        </aside>

        <!-- 2. Main Content Area -->
        <main class="flex-1 p-8 overflow-y-auto">
            
            <!-- Page Header -->
            <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Payroll Processing Center</h1>
                    <p class="text-sm text-gray-500">Review aggregated ledger details and process batch calculations.</p>
                </div>
                
                <!-- Calculation Action Filters Form -->
                <form class="flex flex-wrap items-center gap-3 bg-white p-3 rounded-xl border border-gray-200 shadow-sm">
                    <div>
                        <label for="month" class="sr-only">Month</label>
                        <select id="month" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-indigo-500 focus:border-indigo-500 p-2.5">
                            <option value="6" selected>June</option>
                            <option value="7">July</option>
                            <option value="8">August</option>
                        </select>
                    </div>
                    <div>
                        <label for="year" class="sr-only">Year</label>
                        <select id="year" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-indigo-500 focus:border-indigo-500 p-2.5">
                            <option value="2026" selected>2026</option>
                            <option value="2027">2027</option>
                        </select>
                    </div>
                    <button type="button" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm rounded-lg px-5 py-2.5 shadow-sm shadow-indigo-600/10 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        ⚡ Run Batch Payroll
                    </button>
                </form>
            </header>

            <!-- 3. KPI Summary Cards Matrix -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Card 1 -->
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-500">Total Net Payout</span>
                        <span class="p-2 bg-emerald-50 text-emerald-600 rounded-lg text-sm">💵</span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">$24,850.00</p>
                    <p class="text-xs text-gray-400 mt-1">Sum of net_salary values</p>
                </div>
                <!-- Card 2 -->
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-500">Overtime Disbursed</span>
                        <span class="p-2 bg-amber-50 text-amber-600 rounded-lg text-sm">⏱️</span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">$1,420.00</p>
                    <p class="text-xs text-gray-400 mt-1">Sum of ot_amount values</p>
                </div>
                <!-- Card 3 -->
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-500">Bonuses Applied</span>
                        <span class="p-2 bg-indigo-50 text-indigo-600 rounded-lg text-sm">✨</span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">$2,100.00</p>
                    <p class="text-xs text-gray-400 mt-1">Sum of bonus_amount values</p>
                </div>
                <!-- Card 4 -->
                <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-500">Deductions Withheld</span>
                        <span class="p-2 bg-rose-50 text-rose-600 rounded-lg text-sm">📉</span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">$650.00</p>
                    <p class="text-xs text-gray-400 mt-1">Sum of deduction_amount values</p>
                </div>
            </section>

            <!-- 4. Table UI Ledger Grid View (payrolls data) -->
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="font-bold text-gray-900 text-lg">Processed Salary Sheets</h2>
                    <span class="px-2.5 py-1 text-xs font-semibold bg-indigo-50 text-indigo-700 rounded-full">June 2026 Batch</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-4">Employee ID</th>
                                <th class="px-6 py-4">Name</th>
                                <th class="px-6 py-4 text-right">Base Salary</th>
                                <th class="px-6 py-4 text-right">OT Amount</th>
                                <th class="px-6 py-4 text-right">Bonuses</th>
                                <th class="px-6 py-4 text-right">Deductions</th>
                                <th class="px-6 py-4 text-right font-semibold text-gray-900">Net Salary</th>
                                <th class="px-6 py-4 text-center">Status</th>
                                <th class="px-6 py-4 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <!-- Row 1 -->
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 text-gray-500 font-mono">EMP001</td>
                                <td class="px-6 py-4 font-medium text-gray-900">John Doe</td>
                                <td class="px-6 py-4 text-right font-mono">$5,000.00</td>
                                <td class="px-6 py-4 text-right text-amber-600 font-mono">+$240.00</td>
                                <td class="px-6 py-4 text-right text-emerald-600 font-mono">+$500.00</td>
                                <td class="px-6 py-4 text-right text-rose-600 font-mono">-$120.00</td>
                                <td class="px-6 py-4 text-right font-bold text-slate-900 font-mono">$5,620.00</td>
                                <td class="px-6 py-4 text-center">
                                    <span class="px-2 py-1 text-xs font-medium bg-emerald-100 text-emerald-800 rounded-full">Calculated</span>
                                </td>
