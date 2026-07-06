<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$message = '';
$message_type = '';

// Add department
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_department'])) {
    $department_name = trim($_POST['department_name']);

    if (empty($department_name)) {
        $message = 'Please enter a department name.';
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("INSERT INTO departments (department_name) VALUES (?)");
        $stmt->bind_param('s', $department_name);
        if ($stmt->execute()) {
            $message = 'Department added successfully.';
            $message_type = 'success';
        } else {
            $message = 'Error adding department: ' . (strpos($stmt->error, 'Duplicate') !== false ? 'Department already exists.' : $stmt->error);
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Delete department
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    header('Location: department.php');
    exit;
}

$departments = $conn->query("SELECT d.*, (SELECT COUNT(*) FROM employee e WHERE e.department_id = d.id) as emp_count FROM departments d ORDER BY d.department_name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Departments</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Departments"; $page_subtitle = "Manage company departments and employee distribution."; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2">
                    <?php if (empty($departments)): ?>
                    <section class="glass-strong rounded-2xl p-12 text-center">
                        <svg class="w-24 h-24 mx-auto mb-6 text-zinc-600 dark:text-zinc-700" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="20" y="15" width="60" height="45" rx="6" stroke="currentColor" stroke-width="2" opacity="0.3"/>
                            <rect x="26" y="21" width="48" height="12" rx="3" stroke="currentColor" stroke-width="2" opacity="0.15"/>
                            <line x1="30" y1="40" x2="50" y2="40" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.2"/>
                            <line x1="30" y1="47" x2="45" y2="47" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.15"/>
                            <path d="M68 68l6-6 10 10" stroke="url(#grad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.6"/>
                            <circle cx="60" cy="75" r="14" stroke="currentColor" stroke-width="2" opacity="0.2"/>
                            <circle cx="60" cy="75" r="4" fill="currentColor" opacity="0.15"/>
                            <defs><linearGradient id="grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#e879f9"/></linearGradient></defs>
                        </svg>
                        <h3 class="text-xl font-bold text-white">No departments created</h3>
                        <p class="text-zinc-400 mt-2 max-w-md mx-auto">Departments help you organize your team. Create your first department to get started.</p>
                    </section>
                    <?php else: ?>
                    <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                        <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                            <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-building text-violet-400 mr-2"></i>Department List</h2>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo count($departments); ?> departments</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                    <tr>
                                        <th class="px-6 py-4">Department</th>
                                        <th class="px-6 py-4">Employees</th>
                                        <th class="px-6 py-4 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/[0.06]">
                                    <?php foreach ($departments as $dept): ?>
                                        <tr class="hover:bg-white/[0.02] transition">
                                            <td class="px-6 py-4 font-medium text-white"><?php echo htmlspecialchars($dept['department_name']); ?></td>
                                            <td class="px-6 py-4">
                                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-white/10 text-zinc-300"><?php echo $dept['emp_count']; ?> employees</span>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <a href="?delete=<?php echo $dept['id']; ?>" onclick="return confirm('Delete this department?')" class="text-red-400 hover:text-red-300 text-xs font-medium"><i class="fa-solid fa-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <?php endif; ?>
                </div>

                <div>
                    <section class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="font-bold text-white text-lg mb-6"><i class="fa-solid fa-plus text-violet-400 mr-2"></i>Add Department</h2>
                        <form method="POST" class="space-y-4 text-sm">
                        <?php echo csrf_field(); ?>
                            <div class="floating-group">
                                <input type="text" name="department_name" id="dept_name" required placeholder=" " class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 pt-6 pb-2 text-sm text-white shadow-sm outline-none transition">
                                <label for="dept_name" class="absolute left-4 top-3.5 text-sm text-zinc-500 pointer-events-none transition-all duration-200">Department Name</label>
                            </div>
                            <button type="submit" name="add_department" class="w-full rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-building"></i> Add Department
                            </button>
                        </form>
                    </section>
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
