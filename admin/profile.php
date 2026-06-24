<?php
// Simulated Database: High-fidelity mock data directly in PHP
$employee = [
    'id'                 => 1,
    'name'               => 'Benjamin R. Carter',
    'role'               => 'Senior Solutions Architect',
    'employee_code'      => 'TECH_00492_DE',
    'email'              => 'b.carter@enterprise-solutions.com',
    'phone'              => '+1 (555) 092-4821',
    'location'           => 'Berlin Hub - Floor 4',
    'business_unit'      => 'Core Engineering',
    'annual_gross'       => 114500.00,
    'confidential_notes' => 'Eligibility for Q4 Performance Bonus has been confirmed by VP Engineering. Subject to final board approval on Jan 15th. Recent promotion path initiated for Principal Architect role.'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Profile | Enterprise HR</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-50 text-slate-800 font-sans antialiased">

    <header class="bg-white border-b border-slate-200 sticky top-0 z-10 px-6 py-4 flex justify-between items-center">
        <div class="flex items-center space-x-4">
            <span class="text-xl font-bold text-blue-900 tracking-tight">Enterprise HR</span>
            <nav class="hidden md:flex space-x-1 text-sm font-medium text-slate-600">
                <a href="dashboard.php" class="px-3 py-2 rounded-md hover:bg-slate-100">Dashboard</a>
                <a href="employee.php" class="px-3 py-2 rounded-md bg-blue-50 text-blue-700">Employees</a>
                <a href="insert.php" class="px-3 py-2 rounded-md hover:bg-slate-100">Payroll</a>
            </nav>
        </div>
        <div class="flex items-center space-x-3">
            <button class="bg-blue-900 hover:bg-blue-800 text-white text-sm font-semibold px-4 py-2 rounded shadow-sm transition">
                Edit Profile
            </button>
        </div>
    </header>

    <main class="max-w-7xl mx-auto p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="space-y-6 lg:col-span-1">
            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm text-center lg:text-left">
                <div class="w-24 h-24 bg-blue-100 text-blue-900 rounded-full mx-auto lg:mx-0 flex items-center justify-center text-3xl font-bold mb-4">
                    <?= htmlspecialchars(substr($employee['name'], 0, 2)) ?>
                </div>
                <h1 class="text-2xl font-bold text-slate-900 leading-tight"><?= htmlspecialchars($employee['name']) ?></h1>
                <p class="text-sm font-medium text-blue-700 mt-1"><?= htmlspecialchars($employee['role']) ?></p>
                <span class="inline-block mt-3 bg-slate-100 text-slate-700 text-xs font-mono px-2 py-1 rounded border border-slate-200">
                    <?= htmlspecialchars($employee['employee_code']) ?>
                </span>
            </div>

            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-4">Contact Information</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Work Email</span>
                        <a href="mailto:<?= htmlspecialchars($employee['email']) ?>" class="text-blue-600 hover:underline font-medium"><?= htmlspecialchars($employee['email']) ?></a>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Mobile Phone</span>
                        <span class="text-slate-700 font-medium"><?= htmlspecialchars($employee['phone']) ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Office Location</span>
                        <span class="text-slate-700 font-medium"><?= htmlspecialchars($employee['location']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6 lg:col-span-2">

            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                <h2 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-3 mb-4">Employment Details</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Business Unit</span>
                        <span class="text-slate-800 font-semibold text-sm"><?= htmlspecialchars($employee['business_unit']) ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Contract Type</span>
                        <span class="text-slate-800 font-semibold text-sm">Full-Time (Indefinite)</span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Probation Status</span>
                        <span class="inline-flex items-center mt-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Completed
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6 pt-6 border-t border-slate-100">
                    <div class="bg-slate-50 p-4 rounded-lg border border-slate-200">
                        <span class="block text-xs font-medium text-slate-500">Annual Gross Compensation</span>
                        <span class="text-2xl font-bold text-slate-900 mt-1 block">
                            € <?= number_format($employee['annual_gross'], 2) ?>
                        </span>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-lg border border-slate-200">
                        <span class="block text-xs font-medium text-slate-500">Estimated Monthly Net</span>
                        <span class="text-2xl font-bold text-slate-900 mt-1 block">
                            € <?= number_format($employee['annual_gross'] / 12 * 0.62, 2) ?> <span class="text-xs text-slate-400 font-normal">(Tax Class I)</span>
                        </span>
                    </div>
                </div>
            </div>

            <?php if (!empty($employee['confidential_notes'])): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-5">
                    <div class="flex items-start space-x-3">
                        <span class="text-amber-800 text-sm font-bold whitespace-nowrap">⚠️ Admin Note:</span>
                        <p class="text-sm text-amber-900 italic">
                            "<?= htmlspecialchars($employee['confidential_notes']) ?>"
                        </p>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </main>

    <footer class="text-center py-8 text-xs text-slate-400">
        © 2026 ENTERPRISE HR SYSTEMS - PRIVILEGED ACCESS ONLY
    </footer>
</body>

</html>