<?php
session_start();
require_once "../config/db.php";
if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }

$employee_id = $_SESSION['employee_id'];
$message = '';
$message_type = '';

$emp = $conn->prepare("
    SELECT e.*, d.department_name, p.position_name, epi.*
    FROM employee e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN positions p ON e.position_id = p.id
    LEFT JOIN employee_personal_info epi ON e.id = epi.employee_id
    WHERE e.id = ?
");
$emp->bind_param("i", $employee_id);
$emp->execute();
$employee = $emp->get_result()->fetch_assoc();
$emp->close();

if (!$employee) { header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $gender = trim($_POST['gender']);
    $dob = trim($_POST['dob']);

    $father_name = trim($_POST['father_name']);
    $nrc = trim($_POST['nrc']);
    $married_status = trim($_POST['married_status']);
    $ethnicity = trim($_POST['ethnicity']);
    $religion = trim($_POST['religion']);
    $permanent_address = trim($_POST['permanent_address']);

    if (empty($name)) {
        $message = "Name is required.";
        $message_type = "error";
    } else {
        $stmt = $conn->prepare("UPDATE employee SET name=?, phone=?, gender=?, dob=? WHERE id=?");
        $stmt->bind_param("ssssi", $name, $phone, $gender, $dob, $employee_id);
        $stmt->execute();
        $stmt->close();

        if ($employee['employee_id']) {
            $stmt = $conn->prepare("UPDATE employee_personal_info SET father_name=?, nrc=?, married_status=?, ethnicity=?, religion=?, permanent_address=? WHERE employee_id=?");
            $stmt->bind_param("ssssssi", $father_name, $nrc, $married_status, $ethnicity, $religion, $permanent_address, $employee_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO employee_personal_info (employee_id, father_name, nrc, married_status, ethnicity, religion, permanent_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssss", $employee_id, $father_name, $nrc, $married_status, $ethnicity, $religion, $permanent_address);
        }
        $stmt->execute();
        $stmt->close();

        $message = "Profile updated successfully!";
        $message_type = "success";

        $emp = $conn->prepare("SELECT e.*, d.department_name, p.position_name, epi.* FROM employee e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN positions p ON e.position_id = p.id LEFT JOIN employee_personal_info epi ON e.id = epi.employee_id WHERE e.id = ?");
        $emp->bind_param("i", $employee_id);
        $emp->execute();
        $employee = $emp->get_result()->fetch_assoc();
        $emp->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · My Profile</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php $sidebar_role = 'employee'; include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "My Profile"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-6 lg:p-8 overflow-y-auto page-content w-full">
            <div class="animate-fade-in-up mb-8">
                <h1 class="text-2xl font-bold text-body tracking-tight">My Profile</h1>
                <p class="text-sm text-body-secondary mt-1">Manage your personal information and account details.</p>
            </div>

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?> animate-fade-in-up">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-1 space-y-6">
                    <div class="glass-strong rounded-2xl p-6 text-center card-hover animate-fade-in-up stagger-1">
                        <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-3xl font-bold mx-auto shadow-xl shadow-violet-500/20 ring-2 ring-white/10 animate-float">
                            <?php echo strtoupper(substr($employee['name'], 0, 2)); ?>
                        </div>
                        <h2 class="text-xl font-bold text-body mt-4"><?php echo htmlspecialchars($employee['name']); ?></h2>
                        <p class="text-sm text-body-secondary"><?php echo htmlspecialchars($employee['position_name'] ?? 'No Position'); ?></p>
                        <div class="mt-4 flex justify-center gap-2">
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-violet-500/20 text-violet-400"><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></span>
                            <?php if ($employee['status'] === 'active'): ?>
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-emerald-500/20 text-emerald-400">Active</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-2">
                        <h3 class="font-bold text-white text-sm mb-4 border-b border-white/[0.06] pb-3"><i class="fa-solid fa-briefcase text-violet-400 mr-2"></i>Employment Info</h3>
                        <dl class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Employee Code</dt>
                                <dd class="font-mono text-zinc-300"><?php echo htmlspecialchars($employee['employee_code']); ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Department</dt>
                                <dd class="text-zinc-300"><?php echo htmlspecialchars($employee['department_name'] ?? '-'); ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Position</dt>
                                <dd class="text-zinc-300"><?php echo htmlspecialchars($employee['position_name'] ?? '-'); ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Hire Date</dt>
                                <dd class="text-zinc-300"><?php echo $employee['hire_date'] ? date('M d, Y', strtotime($employee['hire_date'])) : '-'; ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Basic Salary</dt>
                                <dd class="font-mono text-emerald-400 font-semibold">$<?php echo number_format($employee['basic_salary'] ?? 0, 2); ?></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Allowance</dt>
                                <dd class="font-mono text-amber-400 font-semibold">$<?php echo number_format($employee['allowance'] ?? 0, 2); ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="xl:col-span-2">
                    <form method="POST" class="glass-strong rounded-2xl p-6 card-hover animate-fade-in-up stagger-2">
                        <h3 class="font-bold text-white text-base mb-6 border-b border-white/[0.06] pb-4"><i class="fa-solid fa-user-pen text-violet-400 mr-2"></i>Personal Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Full Name *</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($employee['name']); ?>" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Email</label>
                                <input type="email" value="<?php echo htmlspecialchars($employee['email']); ?>" disabled class="w-full px-4 py-2.5 rounded-xl bg-white/[0.03] border border-white/5 text-zinc-400 cursor-not-allowed">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Phone</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Gender</label>
                                <select name="gender" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                                    <option value="Male" <?php echo ($employee['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($employee['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Date of Birth</label>
                                <input type="date" name="dob" value="<?php echo $employee['dob'] ?? ''; ?>" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Father's Name</label>
                                <input type="text" name="father_name" value="<?php echo htmlspecialchars($employee['father_name'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">NRC / ID</label>
                                <input type="text" name="nrc" value="<?php echo htmlspecialchars($employee['nrc'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Marital Status</label>
                                <select name="married_status" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                                    <option value="">Select</option>
                                    <option value="Single" <?php echo ($employee['married_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                    <option value="Married" <?php echo ($employee['married_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                    <option value="Divorced" <?php echo ($employee['married_status'] ?? '') == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Ethnicity</label>
                                <input type="text" name="ethnicity" value="<?php echo htmlspecialchars($employee['ethnicity'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Religion</label>
                                <input type="text" name="religion" value="<?php echo htmlspecialchars($employee['religion'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                            </div>
                            <div class="md:col-span-2 space-y-1.5">
                                <label class="text-sm font-medium text-zinc-300">Permanent Address</label>
                                <textarea name="permanent_address" rows="3" class="w-full px-4 py-2.5 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all"><?php echo htmlspecialchars($employee['permanent_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end">
                            <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500 text-white font-semibold text-sm px-6 py-2.5 shadow-lg transition-all duration-200 hover:-translate-y-0.5">
                                <i class="fa-solid fa-floppy-disk mr-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-6 lg:px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> AURA HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>
