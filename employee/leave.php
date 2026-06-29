<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Off Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    
</head>

<body class="bg-gray-50 font-sans flex min-h-screen">
    <!-- Main Workspace Container -->
    <main class="flex-1 p-8">
        <header class="mb-8">
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Leave Requests</h1>
            <p class="text-sm text-slate-500 mt-1">Approve or reject time-off requests filed by team members.</p>
        </header>

        <!-- Leave Requests Layout Sheet -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider text-xs border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-4">Applicant</th>
                            <th class="px-6 py-4">Type</th>
                            <th class="px-6 py-4">Duration Range</th>
                            <th class="px-6 py-4">Reason Details</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Approvals</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        <tr>
                            <td class="px-6 py-4 font-medium text-slate-900">Jane Smith</td>
                            <td class="px-6 py-4 text-slate-600">Medical Sick Leave</td>
                            <td class="px-6 py-4">
                                <div class="text-slate-900 font-medium">Jun 24 - Jun 26</div>
                                <div class="text-xs text-slate-400">3 Working Days total</div>
                            </td>
                            <td class="px-6 py-4 text-slate-500 max-w-xs truncate">Dental surgery recovery period.</td>
                            <td class="px-6 py-4">
                                <span class="bg-amber-50 text-amber-700 font-medium px-2 py-1 rounded text-xs border border-amber-200">Pending HR Approval</span>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <button class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-xs px-3 py-1.5 rounded shadow-sm mr-2 transition">Approve</button>
                                <button class="border border-slate-200 hover:bg-slate-50 text-slate-600 font-medium text-xs px-3 py-1.5 rounded transition">Reject</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>

</html>