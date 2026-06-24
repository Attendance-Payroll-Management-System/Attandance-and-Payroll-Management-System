<?php
require_once '../config/db.php';

// Initialize variables
$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $name = isset($_POST['name']) ? htmlspecialchars(trim($_POST['name'])) : '';
    $father_name = isset($_POST['father_name']) ? htmlspecialchars(trim($_POST['father_name'])) : '';
    $dob = isset($_POST['dob']) ? $_POST['dob'] : '';
    $gender = isset($_POST['gender']) ? $_POST['gender'] : '';
    $nrc = isset($_POST['nrc']) ? htmlspecialchars(trim($_POST['nrc'])) : '';
    $married_status = isset($_POST['married_status']) ? htmlspecialchars(trim($_POST['married_status'])) : '';
    $ethnicity = isset($_POST['ethnicity']) ? htmlspecialchars(trim($_POST['ethnicity'])) : '';
    $religion = isset($_POST['religion']) ? htmlspecialchars(trim($_POST['religion'])) : '';
    $phone = isset($_POST['phone']) ? htmlspecialchars(trim($_POST['phone'])) : '';
    $permanent_address = isset($_POST['permanent_address']) ? htmlspecialchars(trim($_POST['permanent_address'])) : '';
    $email = isset($_POST['email']) ? htmlspecialchars(trim($_POST['email'])) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $department = isset($_POST['department']) ? $_POST['department'] : '';
    $designation = isset($_POST['designation']) ? $_POST['designation'] : '';
    $doj = isset($_POST['doj']) ? $_POST['doj'] : '';
    $status = isset($_POST['status']) ? $_POST['status'] : 'active';
    $basic_salary = isset($_POST['basic_salary']) ? $_POST['basic_salary'] : 0;

    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($dob)) {
        $message = 'Please fill in all required fields!';
        $message_type = 'error';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match!';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format!';
        $message_type = 'error';
    } else {
        // Check if email already exists
        $check_email = $conn->prepare("SELECT id FROM employee WHERE email = ?");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        $check_email->store_result();

        if ($check_email->num_rows > 0) {
            $message = 'Email already exists!';
            $message_type = 'error';
        } else {
            // Generate employee code
            $employee_code = 'EMP-' . date('Ymd') . '-' . rand(1000, 9999);

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert into employee table
            $sql = "INSERT INTO employee (employee_code, name, gender, dob, phone, email, password, hire_date, basic_salary, status, role) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $role = $designation ?: 'Employee';
            $stmt->bind_param("sssssssssss", $employee_code, $name, $gender, $dob, $phone, $email, $hashed_password, $doj, $basic_salary, $status, $role);

            if ($stmt->execute()) {
                $employee_id = $conn->insert_id;

                // Get allowance value
                $allowance = isset($_POST['allwance']) ? $_POST['allwance'] : 0;

                // Insert personal details into database
                $sql_personal = "INSERT INTO employee_personal_info (employee_id, father_name, nrc, married_status, ethnicity, religion, permanent_address, allowance) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt_personal = $conn->prepare($sql_personal);
                $stmt_personal->bind_param("issssssd", $employee_id, $father_name, $nrc, $married_status, $ethnicity, $religion, $permanent_address, $allowance);

                if ($stmt_personal->execute()) {
                    $message = 'Employee added successfully! Employee Code: ' . $employee_code;
                    $message_type = 'success';
                    // Clear form
                    $_POST = array();
                } else {
                    $message = 'Error saving personal details: ' . $stmt_personal->error;
                    $message_type = 'error';
                }
                $stmt_personal->close();
            } else {
                $message = 'Error: ' . $stmt->error;
                $message_type = 'error';
            }
            $stmt->close();
        }
        $check_email->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management System - Add Employee</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome for Dashboard Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-pZfgWHSbyM6BKej2Xn3FHruSDveLyZWYB+j25B6pjKLFChjYkD2BKufketz4eTFYJtKVe4jUe+q04IyC3LMuoA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body class="min-h-screen antialiased font-sans text-slate-700 bg-[radial-gradient(circle_at_top_left,_rgba(52,211,153,0.16),_transparent_18%),radial-gradient(circle_at_top_right,_rgba(56,189,248,0.12),_transparent_22%),linear-gradient(180deg,_#f8fafc_0%,_#e2e8f0_100%)]">

    <!-- Top Navigation Bar -->
    <header class="bg-white/80 backdrop-blur-lg shadow-sm flex items-center justify-between px-6 py-3 fixed top-0 w-full z-10 h-14">
        <div class="flex items-center space-x-4">
            <button class="text-slate-600 hover:text-slate-900 focus:outline-none">
                <i class="fa-solid fa-bars text-xl"></i>
            </button>
            <div class="text-sm font-semibold text-slate-700">Attendance & Payroll</div>
        </div>
        <div class="flex items-center gap-3 rounded-full border border-slate-200 bg-white px-3 py-1 shadow-sm">
            <div class="w-8 h-8 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-inner">
                <i class="fa-solid fa-user"></i>
            </div>
            <div class="text-sm text-slate-700">Admin</div>
            <i class="fa-solid fa-chevron-down text-xs text-slate-400"></i>
        </div>
    </header>

    <div class="flex pt-16 min-h-screen gap-6 bg-slate-100 px-4 py-6">
        <!-- Sidebar Navigation -->
        <aside class="w-72 rounded-3xl border border-slate-200 bg-slate-900/95 text-slate-300 shadow-xl shadow-slate-900/10 backdrop-blur-xl p-4">
            <!-- Brand Logo Space -->
            <div class="rounded-3xl bg-slate-800/90 p-5 text-center border border-slate-700/60 shadow-inner">
                <div class="text-emerald-400 font-bold text-xl uppercase tracking-[0.2em]">Payroll</div>
                <div class="text-xs text-slate-500 mt-1">Attendance Management</div>
            </div>

            <!-- User Status Panel -->
            <div class="mt-5 rounded-3xl border border-slate-700/70 bg-slate-900/80 p-4 shadow-inner">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-emerald-500/15 text-emerald-400 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-[0.2em]">Welcome back</p>
                        <p class="text-sm font-semibold text-white">Admin</p>
                    </div>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="mt-6 space-y-2">
                <a href="#" class="sidebar-link flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-gauge w-5"></i> Dashboard
                </a>
                <a href="#" class="sidebar-link flex items-center gap-3 rounded-2xl bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-300 shadow-inner hover:bg-emerald-200/20">
                    <i class="fa-solid fa-users w-5"></i> Add Employee
                </a>
                <a href="#" class="sidebar-link flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-sitemap w-5"></i> Department
                </a>
                <a href="#" class="sidebar-link flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-calendar-check w-5"></i> Attendance
                </a>
                <a href="#" class="sidebar-link flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-file-invoice w-5"></i> Leave
                </a>
                <a href="#" class="sidebar-link flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-credit-card w-5"></i> Payroll
                </a>
                <a href="#" class="sidebar-link flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-umbrella-beach w-5"></i> Holiday
                </a>
                <a href="#" class="sidebar-link flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-gear w-5"></i> Settings
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto">
            <?php if ($message): ?>
                <div class="mb-6 rounded-3xl px-6 py-4 shadow-lg border <?php echo $message_type === 'success' ? 'bg-emerald-50/95 border-emerald-200 text-emerald-800' : 'bg-red-50/95 border-red-200 text-red-800'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo $message; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-6 rounded-3xl bg-white/90 p-6 shadow-lg shadow-slate-200/80 border border-slate-200">
                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-sm uppercase tracking-[0.3em] text-emerald-500">Employee Onboarding</p>
                        <h1 class="text-3xl font-semibold text-slate-900">Add New Employee</h1>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm text-slate-500">
                        <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1"> <i class="fa-solid fa-calendar-days text-emerald-500"></i> 12 Jun 2026</span>
                        <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1"> <i class="fa-solid fa-user-check text-emerald-500"></i> New registration</span>
                    </div>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3 mb-6">
                <div class="rounded-3xl p-5 shadow-sm bg-white/90 border border-slate-200/20 backdrop-blur-xl">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Active Employees</p>
                            <p class="mt-3 text-3xl font-semibold text-slate-900">124</p>
                        </div>
                        <div class="inline-flex h-12 w-12 items-center justify-center rounded-3xl bg-emerald-500/10 text-emerald-600">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                </div>
                <div class="rounded-3xl p-5 shadow-sm bg-white/90 border border-slate-200/20 backdrop-blur-xl">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Leave Requests</p>
                            <p class="mt-3 text-3xl font-semibold text-slate-900">8</p>
                        </div>
                        <div class="inline-flex h-12 w-12 items-center justify-center rounded-3xl bg-sky-500/10 text-sky-600">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                    </div>
                </div>
                <div class="rounded-3xl p-5 shadow-sm bg-white/90 border border-slate-200/20 backdrop-blur-xl">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Payroll Due</p>
                            <p class="mt-3 text-3xl font-semibold text-slate-900">$12.4k</p>
                        </div>
                        <div class="inline-flex h-12 w-12 items-center justify-center rounded-3xl bg-cyan-500/10 text-cyan-600">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                    </div>
                </div>
            </div>

            <form action="insert1.php" method="POST" class="grid grid-cols-1 xl:grid-cols-[1.6fr_1fr] gap-6 items-stretch">

                <!-- Left Column: Personal Details Card -->
                <div class="rounded-3xl border border-slate-200/80 bg-green-500/95 shadow-xl shadow-slate-900/5 backdrop-blur-xl">
                    <div class="rounded-t-3xl border-b border-slate-200/80 bg-slate-50 px-6 py-4 text-slate-900 font-semibold shadow-sm">
                        Personal Details
                    </div>
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Father Name</label>
                            <input type="text" name="father_name" value="<?php echo isset($_POST['father_name']) ? htmlspecialchars($_POST['father_name']) : ''; ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Date Of Birth <span class="text-rose-500">*</span></label>
                                <input type="date" name="dob" value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Gender</label>
                                <select name="gender" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">National Registration Card</label>
                            <input type="text" name="nrc" value="<?php echo isset($_POST['nrc']) ? htmlspecialchars($_POST['nrc']) : ''; ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Married Status</label>
                            <input type="text" name="married_status" value="<?php echo isset($_POST['married_status']) ? htmlspecialchars($_POST['married_status']) : ''; ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Ethnicity</label>
                            <input type="text" name="ethnicity" value="<?php echo isset($_POST['ethnicity']) ? htmlspecialchars($_POST['ethnicity']) : ''; ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Religion</label>
                            <input type="text" name="religion" value="<?php echo isset($_POST['religion']) ? htmlspecialchars($_POST['religion']) : ''; ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Phone Number</label>
                            <input type="text" name="phone" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Permanent Address</label>
                            <textarea name="permanent_address" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200"><?php echo isset($_POST['permanent_address']) ? htmlspecialchars($_POST['permanent_address']) : ''; ?></textarea>
                        </div>

                    </div>
                </div>

                <!-- Right Column: Login, Company and Financial Cards -->
                <div class="space-y-6">

                    <!-- Account Login Card -->
                    <div class="rounded-3xl border border-slate-200/80 bg-white/95 shadow-xl shadow-slate-900/5 backdrop-blur-xl">
                        <div class="rounded-t-3xl bg-gradient-to-r from-teal-700 to-sky-500 px-6 py-4 text-white font-semibold shadow-sm">
                            Account Login
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Email <span class="text-rose-500">*</span></label>
                                <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Password <span class="text-rose-500">*</span></label>
                                <input type="password" name="password" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Confirm Password <span class="text-rose-500">*</span></label>
                                <input type="password" name="confirm_password" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                        </div>
                    </div>

                    <!-- Company Details Card -->
                    <div class="rounded-3xl border border-slate-200/80 bg-white/95 shadow-xl shadow-slate-900/5 backdrop-blur-xl">
                        <div class="rounded-t-3xl bg-gradient-to-r from-teal-700 to-sky-500 px-6 py-4 text-white font-semibold shadow-sm">
                            Company Details
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Employee Id</label>
                                <input type="text" name="employee_id" value="Auto Generated" disabled class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500 shadow-sm cursor-not-allowed">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Department</label>
                                <select name="department" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                    <option value="">Select A Department</option>
                                    <option value="hr" <?php echo (isset($_POST['department']) && $_POST['department'] === 'hr') ? 'selected' : ''; ?>>HR Department</option>
                                    <option value="it" <?php echo (isset($_POST['department']) && $_POST['department'] === 'it') ? 'selected' : ''; ?>>IT Department</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Designation</label>
                                <select name="designation" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                    <option value="">Select A Designation</option>
                                    <option value="manager" <?php echo (isset($_POST['designation']) && $_POST['designation'] === 'manager') ? 'selected' : ''; ?>>Manager</option>
                                    <option value="officer" <?php echo (isset($_POST['designation']) && $_POST['designation'] === 'officer') ? 'selected' : ''; ?>>Officer</option>
                                    <option value="staff" <?php echo (isset($_POST['designation']) && $_POST['designation'] === 'staff') ? 'selected' : ''; ?>>Staff</option>
                                </select>
                            </div>
                            <div class="grid gap-4 lg:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-2">Date Of Joining</label>
                                    <input type="date" name="doj" value="<?php echo isset($_POST['doj']) ? htmlspecialchars($_POST['doj']) : ''; ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-2">Status</label>
                                    <select name="status" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                        <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : 'selected'; ?>>Active</option>
                                        <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Details Card -->
                    <div class="rounded-3xl border border-slate-200/80 bg-white/95 shadow-xl shadow-slate-900/5 backdrop-blur-xl">
                        <div class="rounded-t-3xl bg-gradient-to-r from-teal-700 to-sky-500 px-6 py-4 text-white font-semibold shadow-sm">
                            Financial Details
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Basic Salary</label>
                                <input type="number" name="basic_salary" value="<?php echo isset($_POST['basic_salary']) ? htmlspecialchars($_POST['basic_salary']) : ''; ?>" step="0.01" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Allowance</label>
                                <input type="number" name="allwance" value="<?php echo isset($_POST['allwance']) ? htmlspecialchars($_POST['allwance']) : ''; ?>" step="0.01" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <button type="submit" class="w-full rounded-2xl bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:ring-offset-2">
                                Save Employee Information
                            </button>
                        </div>
                    </div>

                </div>
            </form>
        </main>
    </div>
</body>

</html>