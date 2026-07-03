<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';


$result = $conn->query("SELECT
    e.id,
    e.employee_code,
    e.name,
    e.email,
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
    <title>AURA HR · Employees</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Employee Directory"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8">
            <header class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div class="animate-fade-in-up">
                    <h1 class="text-2xl font-bold text-body tracking-tight">Employee Directory</h1>
                    <p class="text-sm text-body-secondary mt-1">Manage active personnel, department routing, and base profiles.</p>
                </div>
                <a href="insert1.php" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Add New Employee
                </a>
            </header>

            <div class="card-hover glass-strong rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                            <tr>
                                <th class="px-6 py-4">#</th>
                                <th class="px-6 py-4">EMP Code</th>
                                <th class="px-6 py-4">Name</th>
                                <th class="px-6 py-4">Email</th>
                                <th class="px-6 py-4">Department</th>
                                <th class="px-6 py-4">Designation</th>
                                <th class="px-6 py-4">Basic Salary</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06] text-zinc-300">
                            <?php foreach ($allemployee as $index => $emp): ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-4"><?php echo $index + 1; ?></td>
                                    <td class="px-6 py-4 font-mono text-xs text-zinc-400"><?php echo $emp['employee_code']; ?></td>
                                    <td class="px-6 py-4 font-medium text-white"><?php echo $emp['name']; ?></td>
                                    <td class="px-6 py-4 text-zinc-400"><?php echo $emp['email']; ?></td>
                                    <td class="px-6 py-4 text-zinc-400"><?php echo $emp['department_name']; ?></td>
                                    <td class="px-6 py-4 text-zinc-400"><?php echo $emp['position_name']; ?></td>
                                    <td class="px-6 py-4 text-zinc-300 font-mono">$<?php echo number_format($emp['basic_salary'], 2); ?></td>
                                    <td class="px-6 py-4">
                                        <?php if ($emp['status'] === 'active'): ?>
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-emerald-500/20 text-emerald-400">Active</span>
                                        <?php else: ?>
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-red-500/20 text-red-400">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right whitespace-nowrap">
                                        <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="text-zinc-500 hover:text-violet-400 font-medium mr-3 text-xs"><i class="fa-solid fa-eye"></i></a>
                                        <a href="edit_employee.php?id=<?php echo $emp['id']; ?>" class="text-violet-400 hover:text-violet-300 font-medium mr-3 text-xs"><i class="fa-solid fa-pen"></i></a>
                                        <form action="employee.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this employee?');" class="inline">
                                            <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                            <button type="submit" name="delete_emp" value="1" class="text-red-400 hover:text-red-300 font-medium text-xs"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($allemployee)): ?>
                                <tr><td colspan="9" class="px-6 py-12 text-center text-zinc-500">No employees found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> ENTERPRISE HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>

</html>
