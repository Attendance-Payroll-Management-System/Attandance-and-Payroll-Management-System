<?php
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
            <a href="insert1.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm px-4 py-2.5 rounded-lg shadow-sm transition">
                + Add New Employee
            </a>
        </header>

        <!-- Employee Records Table -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider text-xs border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-4">Code / Profile</th>
                            <th class="px-6 py-4">EMP-code</th>
                            <th class="px-6 py-4">Name</th>
                            <th class="px-6 py-4">Email</th>
                            <th class="px-6 py-4">Department</th>
                            <th class="px-6 py-4">Designation</th>
                            <th class="px-6 py-4">Basic Salary</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <?php foreach ($allemployee as $index => $emp): ?>
                        <tbody class="divide-y divide-slate-200 text-slate-700">
                            <tr>

                                <td class=" border-b px-4 py-2"><?php echo $index + 1;  ?></td>
                                <td class=" border-b px-4 py-2"><?php echo $emp['employee_code']; ?></td>
                                <td class="px-6 py-4"><?php echo $emp['name']; ?></td>
                                <td class="px-6 py-4"><?php echo $emp['email']; ?></td>
                                <td class="px-6 py-4 text-slate-500"><?php echo $emp['department_name']; ?></td>
                                <td class="px-6 py-4 text-slate-500"><?php echo $emp['position_name']; ?></td>
                                <td class="px-6 py-4 text-slate-500"><?php echo $emp['basic_salary']; ?></td>
                                <td class="px-6 py-4">
                                    <?php if ($emp['status'] === 'active'): ?>
                                        <span class="bg-emerald-50 text-emerald-700 font-medium px-2 py-1 rounded text-xs border border-emerald-200">Active</span>
                                    <?php else: ?>
                                        <span class="bg-red-50 text-red-700 font-medium px-2 py-1 rounded text-xs border border-red-200">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="text-slate-400 hover:text-rose-600 font-medium mr-3">View Detail</a>
                                    <a href="edit_employee.php?id=<?php echo $emp['id']; ?>" class="text-indigo-600 hover:text-indigo-900 font-medium mr-3">Edit</a>
                                    <form action="employee.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this employee?');" class="inline">
                                        <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" name="delete_emp" value="1" class="text-red-500 hover:text-red-700 font-medium">Delete</button>
                                    </form>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                </table>

            </div>
        </div>
    </main>
</body>

</html>