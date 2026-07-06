<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

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
    <title>AURA HR · <?php echo htmlspecialchars($emp['name']); ?></title>
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Employee Profile"; $page_subtitle = "Detailed information about " . htmlspecialchars($emp['name']); $page_actions = '<a href="edit_employee.php?id=' . $emp['id'] . '" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2"><i class="fa-solid fa-pen"></i> Edit Profile</a><a href="employee.php" class="rounded-xl border border-white/10 glass-strong hover:bg-white/[0.06] text-zinc-300 font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2"><i class="fa-solid fa-arrow-left"></i> Back to List</a>'; include "../includes/topbar.php"; ?>

        <main class="flex-1 p-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Left Column: Basic Info -->
                <div class="space-y-6 lg:col-span-1">
                    <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6 text-center lg:text-left">
                        <div class="w-24 h-24 bg-gradient-to-br from-indigo-500 to-purple-600 text-white rounded-full mx-auto lg:mx-0 flex items-center justify-center text-3xl font-bold mb-4 shadow-md">
                            <?php echo htmlspecialchars(substr($emp['name'], 0, 2)); ?>
                        </div>
                        <h1 class="text-2xl font-bold text-white leading-tight"><?php echo htmlspecialchars($emp['name']); ?></h1>
                        <p class="text-sm font-medium text-violet-400 mt-1"><?php echo htmlspecialchars($emp['role'] ?: $emp['position_name']); ?></p>
                        <span class="inline-block mt-3 bg-white/10 text-zinc-400 text-xs font-mono px-2 py-1 rounded border border-white/10">
                            <?php echo htmlspecialchars($emp['employee_code']); ?>
                        </span>
                        <div class="mt-4">
                            <?php if ($emp['status'] === 'active'): ?>
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-emerald-500/20 text-emerald-400">Active</span>
                            <?php else: ?>
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-red-500/20 text-red-400">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-4">Contact Information</h3>
                        <div class="space-y-3 text-sm">
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Work Email</span>
                                <a href="mailto:<?php echo htmlspecialchars($emp['email']); ?>" class="text-violet-400 hover:underline font-medium"><?php echo htmlspecialchars($emp['email']); ?></a>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Mobile Phone</span>
                                <span class="text-zinc-300 font-medium"><?php echo htmlspecialchars($emp['phone'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Date of Birth</span>
                                <span class="text-zinc-300 font-medium"><?php echo htmlspecialchars($emp['dob'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Gender</span>
                                <span class="text-zinc-300 font-medium"><?php echo htmlspecialchars(ucfirst($emp['gender'] ?: 'N/A')); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Details -->
                <div class="space-y-6 lg:col-span-2">

                    <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="text-lg font-bold text-white border-b border-white/[0.06] pb-3 mb-4"><i class="fa-solid fa-briefcase text-violet-400 mr-2"></i>Employment Details</h2>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Department</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['department_name'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Designation</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['position_name'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Role</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['role'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Hire Date</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['hire_date'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Employee Code</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['employee_code']); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Contract Type</span>
                                <span class="text-white font-semibold text-sm">Full-Time</span>
                            </div>
                        </div>
                    </div>

                    <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="text-lg font-bold text-white border-b border-white/[0.06] pb-3 mb-4"><i class="fa-solid fa-user text-violet-400 mr-2"></i>Personal Information</h2>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Father's Name</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['father_name'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">NRC</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['nrc'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Marital Status</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars(ucfirst($emp['married_status'] ?: 'N/A')); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Ethnicity</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['ethnicity'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Religion</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['religion'] ?: 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="block text-xs font-medium text-zinc-500">Permanent Address</span>
                                <span class="text-white font-semibold text-sm"><?php echo htmlspecialchars($emp['permanent_address'] ?: 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Details -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="group glass-strong rounded-2xl p-5">
                            <span class="block text-xs font-medium text-zinc-500">Basic Salary</span>
                            <span class="text-2xl font-bold text-white mt-1 block">
                                $<?php echo number_format($emp['basic_salary'], 2); ?>
                            </span>
                        </div>
                        <div class="group glass-strong rounded-2xl p-5">
                            <span class="block text-xs font-medium text-zinc-500">Allowance</span>
                            <span class="text-2xl font-bold text-white mt-1 block">
                                $<?php echo number_format($emp['allowance'] ?: 0, 2); ?>
                            </span>
                        </div>
                        <div class="bg-gradient-to-br from-emerald-500/10 to-emerald-500/5 rounded-2xl border border-emerald-500/30 p-5 md:col-span-2">
                            <span class="block text-xs font-medium text-emerald-400">Total Compensation (Basic + Allowance)</span>
                            <span class="text-3xl font-bold text-emerald-400 mt-1 block">
                                $<?php echo number_format($emp['total_salary'], 2); ?>
                            </span>
                        </div>
                    </div>

                </div>

            </div>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> AURA HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>

</html>
<?php
mysqli_close($conn);
?>
