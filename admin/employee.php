<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Directory</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 font-sans flex min-h-screen">

    <!-- Persistent Sidebar Navigation Layout -->
    <aside class="w-64 bg-slate-900 text-slate-200  flex-col hidden md:flex border-r border-slate-800">
        <div class="p-6 border-b border-slate-800">
            <h1 class="text-xl font-bold tracking-wider text-white">HRMS Core</h1>
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2 text-sm">
            <a href="dashboard.php" class="flex items-center px-4 py-3 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition">📊 Dashboard</a>
            <a href="employee.php" class="flex items-center px-4 py-3 rounded-lg bg-indigo-600 text-white font-medium">👥 Employees</a>
            <a href="attendance.php" class="flex items-center px-4 py-3 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition">📅 Attendance</a>
            <a href="insert.php" class="flex items-center px-4 py-3 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition">🏖️ Leave Requests</a>
            <a href="profile.php" class="flex items-center px-4 py-3 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-white transition">💰 Payroll Center</a>
        </nav>
    </aside>

    <!-- Main Workspace Container -->
    <main class="flex-1 p-8">
        <!-- Control Header Workspace Bar -->
        <header class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Employee Directory</h1>
                <p class="text-sm text-slate-500 mt-1">Manage active personnel, department routing, and base profiles.</p>
            </div>
            <button class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm px-4 py-2.5 rounded-lg shadow-sm transition">
                + Add New Employee
            </button>
        </header>

        <!-- Employee Records Table -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider text-xs border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-4">Code / Profile</th>
                            <th class="px-6 py-4">Department</th>
                            <th class="px-6 py-4">Position Title</th>
                            <th class="px-6 py-4">Base Salary</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        <tr>
                            <td class="px-6 py-4 flex items-center gap-3">
                                <div class="w-9 h-9 bg-slate-100 rounded-full flex items-center justify-center text-slate-500 font-semibold border">JD</div>
                                <div>
                                    <div class="font-medium text-slate-900">John Doe</div>
                                    <div class="text-xs text-slate-400">EMP001 · john@company.com</div>
                                </div>
                            </td>
                            <td class="px-6 py-4">Engineering</td>
                            <td class="px-6 py-4 text-slate-500">Software Engineer</td>
                            <td class="px-6 py-4 font-mono">$5,000.00</td>
                            <td class="px-6 py-4">
                                <span class="bg-emerald-50 text-emerald-700 font-medium px-2 py-1 rounded text-xs border border-emerald-200">Active</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button class="text-indigo-600 hover:text-indigo-900 font-medium mr-3">Edit</button>
                                <button class="text-slate-400 hover:text-rose-600 font-medium">Archive</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>

</html>