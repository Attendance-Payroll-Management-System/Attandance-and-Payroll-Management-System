<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';


$result = $conn->query("SELECT
    e.id,
    e.employee_code,
    e.name,
    e.email,
    e.profile_photo,
    d.department_name,
    p.position_name,
    e.basic_salary,
    e.status,
    epi.allowance,
    (e.basic_salary + epi.allowance) AS total_salary
FROM employee e
LEFT JOIN departments d
    ON e.department_id = d.id
LEFT JOIN positions p
    ON e.position_id = p.id
LEFT JOIN employee_personal_info epi
    ON e.id = epi.employee_id;");
$allemployee = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) {
        http_response_code(403);
        exit('CSRF validation failed.');
    }
    // Delete Employee from Database
    if (isset($_POST['delete_emp'])) {
        $idToDelete = $_POST['employee_id'];

        $stmt = $conn->prepare("DELETE FROM employee WHERE id = ?");
        $stmt->bind_param('i', $idToDelete);
        $stmt->execute();
        $stmt->close();

        header('Location: employee.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Employees</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Employee Directory";
        $page_subtitle = "Manage active personnel, department routing, and base profiles.";
        $page_actions = '<a href="insert1.php" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2"><i class="fa-solid fa-plus"></i> Add New Employee</a>';
        include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8">

            <?php if (empty($allemployee)): ?>
                <div class="empty-state glass-strong rounded-2xl p-12">
                    <svg class="w-24 h-24 mx-auto mb-6 text-zinc-600 dark:text-zinc-700" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="50" cy="35" r="18" stroke="currentColor" stroke-width="2.5" opacity="0.3" />
                        <path d="M22 80c0-15.46 12.54-28 28-28s28 12.54 28 28" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity="0.3" />
                        <circle cx="50" cy="35" r="18" stroke="currentColor" stroke-width="2.5" stroke-dasharray="4 4" opacity="0.15" />
                        <path d="M72 30l8 6 10-12" stroke="url(#grad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.6" />
                        <line x1="82" y1="72" x2="92" y2="82" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity="0.2" />
                        <circle cx="85" cy="85" r="3" fill="currentColor" opacity="0.15" />
                        <defs>
                            <linearGradient id="grad" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#a78bfa" />
                                <stop offset="100%" stop-color="#e879f9" />
                            </linearGradient>
                        </defs>
                    </svg>
                    <h3 class="text-xl font-bold text-white">No employees yet</h3>
                    <p class="text-zinc-400 mt-2 max-w-md mx-auto">Get started by adding your first team member. You'll be able to assign departments, set salaries, and manage attendance.</p>
                    <a href="insert1.php" class="mt-6 inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition"><i class="fa-solid fa-plus"></i> Add New Employee</a>
                </div>
            <?php else: ?>
                <div class="card-hover glass-strong rounded-2xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                <tr>
                                    <th class="px-4 py-4">#</th>
                                    <th class="px-4 py-4">Photo</th>
                                    <th class="px-4 py-4">EMP Code</th>
                                    <th class="px-4 py-4">Name</th>
                                    <th class="px-4 py-4">Email</th>
                                    <th class="px-4 py-4">Department</th>
                                    <th class="px-4 py-4">Designation</th>
                                    <th class="px-4 py-4">Basic Salary</th>
                                    <th class="px-4 py-4">Status</th>
                                    <th class="px-4 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                                <?php foreach ($allemployee as $index => $emp): ?>
                                    <tr class="hover:bg-white/[0.02] transition">
                                        <td class="px-4 py-4"><?php echo $index + 1; ?></td>
                                        <td class="px-4 py-4">
                                            <div class="w-8 h-8 rounded-full overflow-hidden <?php echo empty($emp['profile_photo']) ? 'bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-xs font-bold text-white' : ''; ?>">
                                                <?php if (!empty($emp['profile_photo'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($emp['profile_photo']); ?>" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars(substr($emp['name'], 0, 2)); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 font-mono text-xs text-zinc-400"><?php echo $emp['employee_code']; ?></td>
                                        <td class="w-full text-zinc-400"><?php echo $emp['name']; ?></td>
                                        <td class="px-4 py-4 text-zinc-400"><?php echo $emp['email']; ?></td>
                                        <td class="px-4 py-4 text-zinc-400"><?php echo $emp['department_name']; ?></td>
                                        <td class="px-4 py-4 text-zinc-400"><?php echo $emp['position_name']; ?></td>
                                        <td class="px-4 py-4 text-zinc-300 font-mono">$<?php echo number_format($emp['basic_salary'], 2); ?></td>
                                        <td class="px-4 py-4">
                                            <?php if ($emp['status'] === 'active'): ?>
                                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-emerald-500/20 text-emerald-400">Active</span>
                                            <?php else: ?>
                                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-red-500/20 text-red-400">Inactive</span>
                                                z <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-right whitespace-nowrap">
                                            <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="text-zinc-500 hover:text-violet-400 font-medium mr-3 text-xs"><i class="fa-solid fa-eye"></i></a>
                                            <a href="edit_employee.php?id=<?php echo $emp['id']; ?>" class="text-violet-400 hover:text-violet-300 font-medium mr-3 text-xs"><i class="fa-solid fa-pen"></i></a>
                                            <form action="employee.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this employee?');" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                                <button type="submit" name="delete_emp" value="1" class="text-red-400 hover:text-red-300 font-medium text-xs"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> HNIN AKARI NWE</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>

</html>