<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

// ─── Handle Department Delete ───
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) {
        http_response_code(403);
        exit('CSRF validation failed.');
    }
    if (isset($_POST['delete_emp'])) {
        $idToDelete = $_POST['employee_id'];
        $stmt = $conn->prepare("DELETE FROM employee WHERE id = ?");
        $stmt->bind_param('i', $idToDelete);
        $stmt->execute();
        $stmt->close();
        header('Location: employee.php' . (!empty($_GET['dept']) ? '?dept=' . intval($_GET['dept']) : ''));
        exit;
    }
}

// ─── Fetch All Departments with Employee Counts and Managers ───
$departments_data = $conn->query("
    SELECT
        d.id AS dept_id,
        d.department_name,
        COUNT(e.id) AS employee_count,
        SUM(CASE WHEN e.status = 'active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN e.status != 'active' THEN 1 ELSE 0 END) AS inactive_count
    FROM departments d
    LEFT JOIN employee e ON e.department_id = d.id
    GROUP BY d.id, d.department_name
    ORDER BY d.department_name ASC
")->fetch_all(MYSQLI_ASSOC);

// Fetch managers (position_name containing 'manager' or 'lead', or first employee per dept)
$managers = $conn->query("
    SELECT
        e.department_id,
        e.id AS manager_id,
        e.name AS manager_name,
        e.profile_photo,
        e.email AS manager_email,
        p.position_name AS manager_position
    FROM employee e
    LEFT JOIN positions p ON e.position_id = p.id
    WHERE e.status = 'active'
    AND e.id IN (
        SELECT MIN(e2.id) FROM employee e2
        WHERE e2.status = 'active'
        GROUP BY e2.department_id
    )
    AND e.department_id IS NOT NULL
")->fetch_all(MYSQLI_ASSOC);
$managers_map = [];
foreach ($managers as $m) {
    $managers_map[$m['department_id']] = $m;
}

// ─── Department Detail View ───
$dept_id = isset($_GET['dept']) ? intval($_GET['dept']) : 0;
$dept_info = null;
$dept_employees = [];
$dept_manager = null;

if ($dept_id > 0) {
    $stmt = $conn->prepare("SELECT id, department_name FROM departments WHERE id = ?");
    $stmt->bind_param("i", $dept_id);
    $stmt->execute();
    $dept_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dept_info) {
        $dept_manager = $managers_map[$dept_id] ?? null;

        // Pagination
        $per_page = 12;
        $page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
        $offset = ($page - 1) * $per_page;

        $count_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM employee WHERE department_id = ?");
        $count_stmt->bind_param("i", $dept_id);
        $count_stmt->execute();
        $total_employees = $count_stmt->get_result()->fetch_assoc()['cnt'];
        $count_stmt->close();
        $total_pages = max(1, ceil($total_employees / $per_page));

        $emp_stmt = $conn->prepare("
            SELECT
                e.id,
                e.employee_code,
                e.name,
                e.email,
                e.phone,
                e.profile_photo,
                e.status,
                e.hire_date,
                e.basic_salary,
                p.position_name,
                epi.allowance,
                (e.basic_salary + COALESCE(epi.allowance, 0)) AS total_salary
            FROM employee e
            LEFT JOIN positions p ON e.position_id = p.id
            LEFT JOIN employee_personal_info epi ON e.id = epi.employee_id
            WHERE e.department_id = ?
            ORDER BY e.name ASC
            LIMIT ? OFFSET ?
        ");
        $emp_stmt->bind_param("iii", $dept_id, $per_page, $offset);
        $emp_stmt->execute();
        $dept_employees = $emp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $emp_stmt->close();
    }
}

// ─── Summary Stats ───
$total_employees_all = $conn->query("SELECT COUNT(*) as cnt FROM employee")->fetch_assoc()['cnt'];
$total_active = $conn->query("SELECT COUNT(*) as cnt FROM employee WHERE status = 'active'")->fetch_assoc()['cnt'];
$total_departments = count($departments_data);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Employee Directory</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="employeeDirectory()" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper flex flex-col min-h-screen">
        <?php
        $page_title = "Employee Directory";
        $page_subtitle = $dept_info ? htmlspecialchars($dept_info['department_name']) . " Department" : "Browse departments and team members";
        $page_actions = '<a href="insert1.php" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2"><i class="fa-solid fa-plus"></i> Add Employee</a>';
        include "../includes/topbar.php";
        ?>

        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full page-enter">

            <?php if ($dept_id > 0 && $dept_info): ?>
                <!-- ═══════════════════════════════════════════════════════════════
                 DEPARTMENT DETAIL VIEW
                 ═══════════════════════════════════════════════════════════════ -->

                <!-- Breadcrumb & Back -->
                <div class="flex items-center justify-between animate-fade-in-up">
                    <div class="flex items-center gap-3">
                        <a href="employee.php" class="w-10 h-10 rounded-xl bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] flex items-center justify-center text-slate-500 dark:text-zinc-400 hover:text-blue-600 dark:hover:text-blue-400 hover:border-blue-300 dark:hover:border-blue-500/30 transition-all duration-200">
                            <i class="fa-solid fa-arrow-left text-sm"></i>
                        </a>
                        <div>
                            <h2 class="text-xl font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($dept_info['department_name']); ?></h2>
                            <p class="text-xs text-slate-500 dark:text-zinc-400"><?php echo $total_employees; ?> employee<?php echo $total_employees !== 1 ? 's' : ''; ?> in this department</p>
                        </div>
                    </div>

                </div>

                <?php if ($dept_manager): ?>
                    <!-- Department Manager Card -->
                    <div class="glass-strong rounded-2xl p-6 animate-fade-in-up stagger-1">
                        <div class="flex items-center gap-5">
                            <div class="relative shrink-0">
                                <div class="w-16 h-16 rounded-full overflow-hidden ring-4 ring-blue-500/20 <?php echo empty($dept_manager['profile_photo']) ? 'bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-xl font-bold text-white' : ''; ?>">
                                    <?php if (!empty($dept_manager['profile_photo'])): ?>
                                        <img src="../<?php echo htmlspecialchars($dept_manager['profile_photo']); ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <?php echo htmlspecialchars(substr($dept_manager['manager_name'], 0, 2)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="absolute -bottom-1 -right-1 w-6 h-6 bg-amber-400 rounded-full flex items-center justify-center border-2 border-white dark:border-[#0F172A] shadow-sm">
                                    <i class="fa-solid fa-crown text-[8px] text-white"></i>
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-blue-500 dark:text-blue-400 mb-1">Department Manager</p>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($dept_manager['manager_name']); ?></h3>
                                <p class="text-xs text-slate-500 dark:text-zinc-400"><?php echo htmlspecialchars($dept_manager['manager_position'] ?: 'Manager'); ?></p>
                            </div>
                            <a href="view_employee.php?id=<?php echo $dept_manager['manager_id']; ?>" class="hidden sm:flex items-center gap-2 px-4 py-2.5 rounded-xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 text-xs font-semibold hover:bg-blue-100 dark:hover:bg-blue-500/20 transition-colors">
                                <i class="fa-solid fa-eye"></i> View Profile
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Search & Filter Bar -->
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-2">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="relative flex-1">
                            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-500 text-sm"></i>
                            <input type="text" x-model="searchQuery" @input="filterEmployees()" placeholder="Search by name, code, email, or position..."
                                class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-slate-100 dark:bg-white/[0.05] border border-slate-200 dark:border-white/[0.08] text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-zinc-500 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-400 dark:focus:border-blue-500/50 transition-all duration-200">
                        </div>
                        <div class="flex items-center gap-2">
                            <select x-model="statusFilter" @change="filterEmployees()" class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-white/[0.05] border border-slate-200 dark:border-white/[0.08] text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-400 dark:focus:border-blue-500/50 transition-all duration-200">
                                <option value="all">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Employee Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
                    <?php foreach ($dept_employees as $i => $emp): ?>
                        <div class="glass-strong rounded-2xl p-6 card-hover group animate-card-reveal flex flex-col items-center text-center"
                            style="animation-delay: <?php echo ($i % 12) * 0.05; ?>s"
                            x-show="filteredEmployees[<?php echo $i; ?>]">

                            <!-- Circular Avatar -->
                            <div class="relative mb-4">
                                <div class="w-20 h-20 rounded-full overflow-hidden ring-4 ring-blue-500/10 group-hover:ring-blue-500/20 transition-all duration-300 <?php echo empty($emp['profile_photo']) ? 'bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-xl font-bold text-white' : ''; ?>">
                                    <?php if (!empty($emp['profile_photo'])): ?>
                                        <img src="../<?php echo htmlspecialchars($emp['profile_photo']); ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <?php echo htmlspecialchars(substr($emp['name'], 0, 2)); ?>
                                    <?php endif; ?>
                                </div>
                                <span class="absolute bottom-0 right-0 w-4 h-4 rounded-full border-2 border-white dark:border-[#0F172A] <?php echo $emp['status'] === 'active' ? 'bg-emerald-500' : 'bg-slate-400'; ?>"></span>
                            </div>

                            <!-- Name -->
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-1 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors"><?php echo htmlspecialchars($emp['name']); ?></h3>

                            <!-- Position -->
                            <p class="text-xs text-slate-500 dark:text-zinc-400 mb-1"><?php echo htmlspecialchars($emp['position_name'] ?: 'Employee'); ?></p>

                            <!-- Employee Code -->
                            <p class="text-[10px] font-mono text-slate-400 dark:text-zinc-500 mb-3"><?php echo htmlspecialchars($emp['employee_code']); ?></p>

                            <!-- Status Badge -->
                            <?php if ($emp['status'] === 'active'): ?>
                                <span class="inline-flex rounded-full px-3 py-1 text-[10px] font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 mb-4">Active</span>
                            <?php else: ?>
                                <span class="inline-flex rounded-full px-3 py-1 text-[10px] font-bold bg-slate-100 dark:bg-slate-500/10 text-slate-500 dark:text-zinc-400 border border-slate-200 dark:border-white/[0.08] mb-4">Inactive</span>
                            <?php endif; ?>

                            <!-- Email -->
                            <div class="flex items-center justify-center gap-1.5 text-xs text-slate-500 dark:text-zinc-500 mb-4 w-full">
                                <i class="fa-solid fa-envelope text-[10px] shrink-0"></i>
                                <span class="truncate"><?php echo htmlspecialchars($emp['email'] ?: 'N/A'); ?></span>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex items-center gap-2 w-full mt-auto">
                                <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="flex-1 text-center py-2.5 rounded-xl bg-slate-100 dark:bg-white/[0.05] border border-slate-200 dark:border-white/[0.08] text-slate-600 dark:text-zinc-300 text-xs font-semibold hover:bg-blue-50 dark:hover:bg-blue-500/10 hover:text-blue-600 dark:hover:text-blue-400 hover:border-blue-200 dark:hover:border-blue-500/20 transition-all duration-200">
                                    <i class="fa-solid fa-eye mr-1"></i> View
                                </a>
                                <a href="edit_employee.php?id=<?php echo $emp['id']; ?>" class="flex-1 text-center py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold shadow-sm transition-all duration-200 hover:shadow-md hover:-translate-y-0.5">
                                    <i class="fa-solid fa-pen mr-1"></i> Edit
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- No Results -->
                <div x-show="visibleCount === 0" class="hidden text-center py-12">
                    <i class="fa-solid fa-users-slash text-4xl text-slate-300 dark:text-zinc-600 mb-4"></i>
                    <h3 class="text-lg font-bold text-slate-700 dark:text-zinc-300">No employees found</h3>
                    <p class="text-sm text-slate-500 dark:text-zinc-500 mt-1">Try adjusting your search or filter criteria.</p>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="flex items-center justify-center gap-2 animate-fade-in-up">
                        <?php if ($page > 1): ?>
                            <a href="?dept=<?php echo $dept_id; ?>&p=<?php echo $page - 1; ?>" class="w-9 h-9 rounded-xl bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] flex items-center justify-center text-slate-500 dark:text-zinc-400 hover:border-blue-300 dark:hover:border-blue-500/30 hover:text-blue-600 dark:hover:text-blue-400 transition-all duration-200">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($start_page > 1): ?>
                            <a href="?dept=<?php echo $dept_id; ?>&p=1" class="w-9 h-9 rounded-xl bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] flex items-center justify-center text-slate-500 dark:text-zinc-400 hover:border-blue-300 dark:hover:border-blue-500/30 hover:text-blue-600 dark:hover:text-blue-400 transition-all duration-200 text-xs font-semibold">1</a>
                            <?php if ($start_page > 2): ?><span class="text-slate-400 dark:text-zinc-500 text-xs">...</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?dept=<?php echo $dept_id; ?>&p=<?php echo $i; ?>" class="w-9 h-9 rounded-xl flex items-center justify-center text-xs font-semibold transition-all duration-200 <?php echo $i === $page ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20' : 'bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] text-slate-500 dark:text-zinc-400 hover:border-blue-300 dark:hover:border-blue-500/30 hover:text-blue-600 dark:hover:text-blue-400'; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?><span class="text-slate-400 dark:text-zinc-500 text-xs">...</span><?php endif; ?>
                            <a href="?dept=<?php echo $dept_id; ?>&p=<?php echo $total_pages; ?>" class="w-9 h-9 rounded-xl bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] flex items-center justify-center text-slate-500 dark:text-zinc-400 hover:border-blue-300 dark:hover:border-blue-500/30 hover:text-blue-600 dark:hover:text-blue-400 transition-all duration-200 text-xs font-semibold"><?php echo $total_pages; ?></a>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?dept=<?php echo $dept_id; ?>&p=<?php echo $page + 1; ?>" class="w-9 h-9 rounded-xl bg-white dark:bg-white/[0.06] border border-slate-200 dark:border-white/[0.08] flex items-center justify-center text-slate-500 dark:text-zinc-400 hover:border-blue-300 dark:hover:border-blue-500/30 hover:text-blue-600 dark:hover:text-blue-400 transition-all duration-200">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- ═══════════════════════════════════════════════════════════════
                 DEPARTMENT GRID VIEW (Default)
                 ═══════════════════════════════════════════════════════════════ -->

                <!-- Summary Stats -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 animate-fade-in-up">
                    <div class="glass-strong rounded-2xl p-5 card-hover">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-500 dark:text-blue-400 flex items-center justify-center text-lg">
                                <i class="fa-solid fa-building"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-zinc-500">Departments</p>
                                <p class="text-2xl font-extrabold text-gradient-navy"><?php echo $total_departments; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="glass-strong rounded-2xl p-5 card-hover">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center text-lg">
                                <i class="fa-solid fa-users"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-zinc-500">Total Employees</p>
                                <p class="text-2xl font-extrabold text-gradient-emerald"><?php echo $total_employees_all; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="glass-strong rounded-2xl p-5 card-hover">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500/20 to-green-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center text-lg">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-zinc-500">Active</p>
                                <p class="text-2xl font-extrabold text-white dark:text-white"><?php echo $total_active; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="glass-strong rounded-2xl p-5 card-hover">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-slate-100 dark:from-slate-500/20 to-slate-200 dark:to-slate-500/10 text-slate-500 dark:text-zinc-400 flex items-center justify-center text-lg">
                                <i class="fa-solid fa-user-xmark"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-zinc-500">Inactive</p>
                                <p class="text-2xl font-extrabold text-white dark:text-white"><?php echo $total_employees_all - $total_active; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search & Filter -->
                <div class="glass-strong rounded-2xl p-4 animate-fade-in-up stagger-1">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="relative flex-1">
                            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-500 text-sm"></i>
                            <input type="text" x-model="searchQuery" @input="filterDepartments()" placeholder="Search departments..."
                                class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-slate-100 dark:bg-white/[0.05] border border-slate-200 dark:border-white/[0.08] text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-zinc-500 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-400 dark:focus:border-blue-500/50 transition-all duration-200">
                        </div>
                    </div>
                </div>

                <?php if (empty($departments_data)): ?>
                    <!-- Empty State -->
                    <div class="glass-strong rounded-2xl p-12 text-center animate-fade-in-up stagger-2">
                        <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-blue-500/20 to-indigo-500/20 text-blue-400 flex items-center justify-center text-3xl mx-auto mb-5">
                            <i class="fa-solid fa-building"></i>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white">No departments found</h3>
                        <p class="text-sm text-slate-500 dark:text-zinc-400 mt-2 max-w-md mx-auto">Create departments first to organize your employees. Each department will show its manager and team members.</p>
                    </div>
                <?php else: ?>

                    <!-- Department Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        <?php foreach ($departments_data as $i => $dept):
                            $mgr = $managers_map[$dept['dept_id']] ?? null;
                            $active_pct = $dept['employee_count'] > 0 ? round(($dept['active_count'] / $dept['employee_count']) * 100) : 0;
                        ?>
                            <a href="employee.php?dept=<?php echo $dept['dept_id']; ?>"
                                class="glass-strong rounded-2xl p-6 card-hover group animate-card-reveal flex flex-col items-center text-center relative overflow-hidden"
                                style="animation-delay: <?php echo $i * 0.06; ?>s"
                                x-show="filteredDepts[<?php echo $i; ?>]">

                                <!-- Decorative corner gradient -->
                                <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-bl from-blue-500/10 to-transparent rounded-bl-full opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>

                                <!-- Manager Avatar -->
                                <div class="relative mb-4  z-10">
                                    <?php if ($mgr): ?>
                                        <div class="w-16 h-16 rounded-full overflow-hidden ring-4 ring-blue-500/15 group-hover:ring-blue-500/25 transition-all duration-300 <?php echo empty($mgr['profile_photo']) ? 'bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-lg font-bold text-white' : ''; ?>">
                                            <?php if (!empty($mgr['profile_photo'])): ?>
                                                <img src="../<?php echo htmlspecialchars($mgr['profile_photo']); ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars(substr($mgr['manager_name'], 0, 2)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-amber-400 rounded-full flex items-center justify-center border-2 border-white dark:border-[#0F172A] shadow-sm">
                                            <i class="fa-solid fa-crown text-[7px] text-white"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-white/[0.05] flex items-center justify-center ring-4 ring-slate-200 dark:ring-white/[0.05]">
                                            <i class="fa-solid fa-user-slash text-xl text-slate-400 dark:text-zinc-500"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Manager Name -->
                                <?php if ($mgr): ?>
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-0.5 relative z-10 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors"><?php echo htmlspecialchars($mgr['manager_name']); ?></h3>
                                    <p class="text-xs text-slate-500 dark:text-zinc-400 mb-3 relative z-10"><?php echo htmlspecialchars($mgr['manager_position'] ?: 'Department Manager'); ?></p>
                                <?php else: ?>
                                    <h3 class="text-sm font-bold text-slate-400 dark:text-zinc-500 mb-0.5 relative z-10">No Manager</h3>
                                    <p class="text-xs text-slate-400 dark:text-zinc-500 mb-3 relative z-10">Unassigned</p>
                                <?php endif; ?>

                                <!-- Department Name -->
                                <div class="w-full px-4 py-3 rounded-xl bg-gradient-to-r from-blue-500/10 to-indigo-500/10 dark:from-blue-500/10 dark:to-indigo-500/10 mb-4 relative z-10">
                                    <p class="text-xs font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider"><?php echo htmlspecialchars($dept['department_name']); ?></p>
                                    <p class="text-[10px] text-slate-500 dark:text-zinc-500 mt-0.5"><?php echo $dept['employee_count']; ?> employee<?php echo $dept['employee_count'] !== 1 ? 's' : ''; ?></p>
                                </div>

                                <!-- Stats Bar -->
                                <div class="w-full flex items-center gap-3 relative z-10">
                                    <div class="flex-1">
                                        <div class="h-1.5 rounded-full bg-slate-100 dark:bg-white/[0.05] overflow-hidden">
                                            <div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-emerald-400 transition-all duration-700" style="width: <?php echo $active_pct; ?>%"></div>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-bold text-emerald-500 dark:text-emerald-400"><?php echo $active_pct; ?>%</span>
                                </div>

                                <!-- Employee Count Badges -->
                                <div class="flex items-center justify-center gap-2 mt-3 relative z-10">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-[10px] font-bold">
                                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span> <?php echo $dept['active_count']; ?> active
                                    </span>
                                    <?php if ($dept['inactive_count'] > 0): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-500/10 text-slate-500 dark:text-zinc-400 text-[10px] font-bold">
                                            <span class="w-1.5 h-1.5 bg-slate-400 rounded-full"></span> <?php echo $dept['inactive_count']; ?> inactive
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- No Search Results -->
                    <div x-show="deptVisibleCount === 0" class="hidden text-center py-12">
                        <i class="fa-solid fa-building-circle-xmark text-4xl text-slate-300 dark:text-zinc-600 mb-4"></i>
                        <h3 class="text-lg font-bold text-slate-700 dark:text-zinc-300">No departments found</h3>
                        <p class="text-sm text-slate-500 dark:text-zinc-500 mt-1">Try a different search term.</p>
                    </div>

                <?php endif; ?>
            <?php endif; ?>
        </main>

    </div>

    <script>
        function employeeDirectory() {
            return {
                searchQuery: '',
                statusFilter: 'all',
                filteredEmployees: <?php echo $dept_id > 0 ? json_encode(array_fill(0, count($dept_employees), true)) : '[]'; ?>,
                filteredDepts: <?php echo $dept_id === 0 ? json_encode(array_fill(0, count($departments_data), true)) : '[]'; ?>,
                visibleCount: <?php echo $dept_id > 0 ? count($dept_employees) : 0; ?>,
                deptVisibleCount: <?php echo $dept_id === 0 ? count($departments_data) : 0; ?>,

                filterEmployees() {
                    const q = this.searchQuery.toLowerCase().trim();
                    const status = this.statusFilter;
                    let count = 0;
                    const employees = <?php echo json_encode($dept_employees); ?>;
                    this.filteredEmployees = employees.map(emp => {
                        const matchSearch = !q ||
                            (emp.name || '').toLowerCase().includes(q) ||
                            (emp.employee_code || '').toLowerCase().includes(q) ||
                            (emp.email || '').toLowerCase().includes(q) ||
                            (emp.position_name || '').toLowerCase().includes(q) ||
                            (emp.phone || '').toLowerCase().includes(q);
                        const matchStatus = status === 'all' || emp.status === status;
                        const show = matchSearch && matchStatus;
                        if (show) count++;
                        return show;
                    });
                    this.visibleCount = count;
                },

                filterDepartments() {
                    const q = this.searchQuery.toLowerCase().trim();
                    let count = 0;
                    const depts = <?php echo json_encode($departments_data); ?>;
                    const managers = <?php echo json_encode($managers_map); ?>;
                    this.filteredDepts = depts.map(dept => {
                        const mgr = managers[dept.dept_id] || {};
                        const matchSearch = !q ||
                            (dept.department_name || '').toLowerCase().includes(q) ||
                            (mgr.manager_name || '').toLowerCase().includes(q) ||
                            (mgr.manager_position || '').toLowerCase().includes(q);
                        if (matchSearch) count++;
                        return matchSearch;
                    });
                    this.deptVisibleCount = count;
                }
            };
        }
    </script>

    <?php include "../includes/footer.php"; ?>
</body>

</html>