<?php
require_once '../config/db.php';

$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($employee_id <= 0) {
    header('Location: employee.php');
    exit;
}

$stmt = $conn->prepare("SELECT
    e.id,
    e.employee_code,
    e.name,
    e.gender,
    e.dob,
    e.phone,
    e.email,
    e.role,
    e.hire_date,
    e.basic_salary,
    e.status,
    d.department_name,
    p.position_name,
    epi.father_name,
    epi.nrc,
    epi.married_status,
    epi.ethnicity,
    epi.religion,
    epi.permanent_address,
    epi.allowance,
    (e.basic_salary + COALESCE(epi.allowance, 0)) AS total_salary
FROM employee e
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN positions p ON e.position_id = p.id
LEFT JOIN employee_personal_info epi ON e.id = epi.employee_id
WHERE e.id = ?");

$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$emp = $result->fetch_assoc();
$stmt->close();

if (!$emp) {
    header('Location: employee.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Profile - <?php echo htmlspecialchars($emp['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-50 text-slate-800 font-sans antialiased">

    <header class="bg-white border-b border-slate-200 sticky top-0 z-10 px-6 py-4 flex justify-between items-center">
        <div class="flex items-center space-x-4">
            <span class="text-xl font-bold text-blue-900 tracking-tight">HRMS Core</span>
            <nav class="hidden md:flex space-x-1 text-sm font-medium text-slate-600">
                <a href="dashboard.php" class="px-3 py-2 rounded-md hover:bg-slate-100">Dashboard</a>
                <a href="employee.php" class="px-3 py-2 rounded-md bg-blue-50 text-blue-700">Employees</a>
                <a href="payroll.php" class="px-3 py-2 rounded-md hover:bg-slate-100">Payroll</a>
            </nav>
        </div>
        <div class="flex items-center space-x-3">
            <a href="edit_employee.php?id=<?php echo $emp['id']; ?>" class="bg-blue-900 hover:bg-blue-800 text-white text-sm font-semibold px-4 py-2 rounded shadow-sm transition">
                Edit Profile
            </a>
            <a href="employee.php" class="text-slate-500 hover:text-slate-700 text-sm font-medium px-4 py-2">Back to List</a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left Column: Basic Info -->
        <div class="space-y-6 lg:col-span-1">
            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm text-center lg:text-left">
                <div class="w-24 h-24 bg-blue-100 text-blue-900 rounded-full mx-auto lg:mx-0 flex items-center justify-center text-3xl font-bold mb-4">
                    <?php echo htmlspecialchars(substr($emp['name'], 0, 2)); ?>
                </div>
                <h1 class="text-2xl font-bold text-slate-900 leading-tight"><?php echo htmlspecialchars($emp['name']); ?></h1>
                <p class="text-sm font-medium text-blue-700 mt-1"><?php echo htmlspecialchars($emp['role'] ?: $emp['position_name']); ?></p>
                <span class="inline-block mt-3 bg-slate-100 text-slate-700 text-xs font-mono px-2 py-1 rounded border border-slate-200">
                    <?php echo htmlspecialchars($emp['employee_code']); ?>
                </span>
                <div class="mt-4">
                    <?php if ($emp['status'] === 'active'): ?>
                        <span class="bg-emerald-50 text-emerald-700 font-medium px-3 py-1 rounded-full text-xs border border-emerald-200">Active</span>
                    <?php else: ?>
                        <span class="bg-red-50 text-red-700 font-medium px-3 py-1 rounded-full text-xs border border-red-200">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-4">Contact Information</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Work Email</span>
                        <a href="mailto:<?php echo htmlspecialchars($emp['email']); ?>" class="text-blue-600 hover:underline font-medium"><?php echo htmlspecialchars($emp['email']); ?></a>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Mobile Phone</span>
                        <span class="text-slate-700 font-medium"><?php echo htmlspecialchars($emp['phone'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Date of Birth</span>
                        <span class="text-slate-700 font-medium"><?php echo htmlspecialchars($emp['dob'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Gender</span>
                        <span class="text-slate-700 font-medium"><?php echo htmlspecialchars(ucfirst($emp['gender'] ?: 'N/A')); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Details -->
        <div class="space-y-6 lg:col-span-2">

            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                <h2 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-3 mb-4">Employment Details</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Department</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['department_name'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Designation</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['position_name'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Role</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['role'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Hire Date</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['hire_date'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Employee Code</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['employee_code']); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Contract Type</span>
                        <span class="text-slate-800 font-semibold text-sm">Full-Time</span>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                <h2 class="text-lg font-bold text-slate-900 border-b border-slate-100 pb-3 mb-4">Personal Information</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Father's Name</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['father_name'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">NRC</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['nrc'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Marital Status</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars(ucfirst($emp['married_status'] ?: 'N/A')); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Ethnicity</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['ethnicity'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Religion</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['religion'] ?: 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="block text-xs font-medium text-slate-400">Permanent Address</span>
                        <span class="text-slate-800 font-semibold text-sm"><?php echo htmlspecialchars($emp['permanent_address'] ?: 'N/A'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Financial Details -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-slate-50 p-4 rounded-lg border border-slate-200">
                    <span class="block text-xs font-medium text-slate-500">Basic Salary</span>
                    <span class="text-2xl font-bold text-slate-900 mt-1 block">
                        $ <?php echo number_format($emp['basic_salary'], 2); ?>
                    </span>
                </div>
                <div class="bg-slate-50 p-4 rounded-lg border border-slate-200">
                    <span class="block text-xs font-medium text-slate-500">Allowance</span>
                    <span class="text-2xl font-bold text-slate-900 mt-1 block">
                        $ <?php echo number_format($emp['allowance'] ?: 0, 2); ?>
                    </span>
                </div>
                <div class="bg-emerald-50 p-4 rounded-lg border border-emerald-200 md:col-span-2">
                    <span class="block text-xs font-medium text-emerald-600">Total Compensation (Basic + Allowance)</span>
                    <span class="text-3xl font-bold text-emerald-900 mt-1 block">
                        $ <?php echo number_format($emp['total_salary'], 2); ?>
                    </span>
                </div>
            </div>



        </div>

    </main>

</body>

</html>
<?php
mysqli_close($conn);
?>