<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

// Initialize variables
$message = '';
$message_type = '';

// 1. FETCH DEPARTMENTS FIRST (Moved to top so it's ready for the form)
// Note: Changed 'departments' to 'department' to match your database structure
$sql_dept = "SELECT id, department_name FROM departments";
$result_dept = mysqli_query($conn, $sql_dept);

$sql_dept = "SELECT id, position_name FROM positions";
$result_opt = mysqli_query($conn, $sql_dept);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) { http_response_code(403); exit('CSRF validation failed.'); }
    // Get form data
    $name = isset($_POST['name']) ? htmlspecialchars(trim($_POST['name'])) : '';
    $father_name = isset($_POST['father_name']) ? htmlspecialchars(trim($_POST['father_name'])) : '';
    $dob = isset($_POST['dob']) ? $_POST['dob'] : '';
    $gender = isset($_POST['gender']) ? $_POST['gender'] : '';
    $nrc_state = $_POST['nrc_state'] ?? '';
    $nrc_township = $_POST['nrc_township'] ?? '';
    $nrc_citizenship = $_POST['nrc_citizenship'] ?? 'N';
    $nrc_number = $_POST['nrc_number'] ?? '';
    $nrc = build_nrc($nrc_state, $nrc_township, $nrc_citizenship, $nrc_number);
    $married_status = isset($_POST['married_status']) ? htmlspecialchars(trim($_POST['married_status'])) : '';
    $ethnicity = isset($_POST['ethnicity']) ? htmlspecialchars(trim($_POST['ethnicity'])) : '';
    $religion = isset($_POST['religion']) ? htmlspecialchars(trim($_POST['religion'])) : '';
    $phone = isset($_POST['phone']) ? htmlspecialchars(trim($_POST['phone'])) : '';
    $permanent_address = isset($_POST['permanent_address']) ? htmlspecialchars(trim($_POST['permanent_address'])) : '';
    $email = isset($_POST['email']) ? htmlspecialchars(trim($_POST['email'])) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $department_id = isset($_POST['department_id']) ? $_POST['department_id'] : null;
    $position_id   = isset($_POST['position_id']) ? $_POST['position_id'] : null;
    $designation = isset($_POST['designation']) ? $_POST['designation'] : '';
    $doj = isset($_POST['doj']) ? $_POST['doj'] : '';
    $status = isset($_POST['status']) ? $_POST['status'] : 'active';
    $basic_salary = isset($_POST['basic_salary']) ? $_POST['basic_salary'] : 0;

    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($dob) || empty($department_id)) {
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

            $sql = "INSERT INTO employee (department_id, position_id, employee_code, name, gender, dob, phone, email, password, hire_date, basic_salary, status, role) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $role = $designation ?: 'Employee';
            $stmt->bind_param(
                "sssssssssssss",
                $department_id,
                $position_id,
                $employee_code,
                $name,
                $gender,
                $dob,
                $phone,
                $email,
                $hashed_password,
                $doj,
                $basic_salary,
                $status,
                $role
            );

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
    <title>Add Employee | Enterprise HR</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Add Employee"; $page_subtitle = "Create a new employee record with personal, company, and financial details."; $page_actions = '<span class="inline-flex items-center gap-2 rounded-full bg-card-custom px-3 py-1 border border-body text-sm text-body-secondary"><i class="fa-solid fa-calendar-days text-violet-400"></i> ' . date('d M Y') . '</span>'; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type === 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo $message; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- FORM START -->
            <form action="insert1.php" method="POST" class="grid grid-cols-1 xl:grid-cols-[1.6fr_1fr] gap-6 items-stretch">
            <?php echo csrf_field(); ?>

                <!-- Left Column: Personal Details Card -->
                <div class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200">
                    <div class="rounded-t-2xl border-b border-white/[0.06] bg-white/[0.04] px-6 py-4 text-white font-semibold shadow-sm">
                        <i class="fa-solid fa-user text-violet-400 mr-2"></i>Personal Details
                    </div>
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Full Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" id="emp_name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="Enter employee name">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Father Name</label>
                            <input type="text" name="father_name" id="father_name" value="<?php echo isset($_POST['father_name']) ? htmlspecialchars($_POST['father_name']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="Enter father name">
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Date Of Birth <span class="text-rose-500">*</span></label>
                                <input type="date" name="dob" value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Gender</label>
                                <select name="gender" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5">National Registration Card</label>
                            <div class="grid grid-cols-12 gap-2" id="nrc-container">
                                <div class="col-span-3">
                                    <select name="nrc_state" id="nrc_state" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-2 py-3 text-sm text-white outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                        <option value="">State</option>
                                        <?php foreach (get_nrc_state_codes() as $val => $label): ?>
                                            <option value="<?php echo $val; ?>" <?php echo (isset($_POST['nrc_state']) && $_POST['nrc_state'] === $val) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <span class="col-span-1 flex items-center justify-center text-zinc-500 text-lg font-bold">/</span>
                                <div class="col-span-4">
                                    <select name="nrc_township" id="nrc_township" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-2 py-3 text-sm text-white outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                        <option value="">Township</option>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <select name="nrc_citizenship" id="nrc_citizenship" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-2 py-3 text-sm text-white outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                        <?php foreach (get_nrc_citizenship_types() as $val => $label): ?>
                                            <option value="<?php echo $val; ?>" <?php echo (isset($_POST['nrc_citizenship']) && $_POST['nrc_citizenship'] === $val) ? 'selected' : ''; ?>><?php echo $val; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <input type="text" name="nrc_number" id="nrc_number" value="<?php echo isset($_POST['nrc_number']) ? htmlspecialchars($_POST['nrc_number']) : ''; ?>" maxlength="6" placeholder="123456" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-2 py-3 text-sm text-white placeholder-zinc-500 outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                </div>
                            </div>
                            <div id="nrc-preview" class="mt-2 text-xs text-zinc-400 <?php echo isset($_POST['nrc_state']) ? '' : 'hidden'; ?>">
                                NRC: <span id="nrc-preview-value" class="text-violet-400 font-mono font-semibold"><?php echo isset($_POST['nrc_state']) ? htmlspecialchars($nrc) : ''; ?></span>
                            </div>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Married Status</label>
                                <select name="married_status" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">Select Status</option>
                                    <option value="Single" <?php echo (isset($_POST['married_status']) && $_POST['married_status'] === 'Single') ? 'selected' : ''; ?>>Single</option>
                                    <option value="Married" <?php echo (isset($_POST['married_status']) && $_POST['married_status'] === 'Married') ? 'selected' : ''; ?>>Married</option>
                                    <option value="Divorced" <?php echo (isset($_POST['married_status']) && $_POST['married_status'] === 'Divorced') ? 'selected' : ''; ?>>Divorced</option>
                                    <option value="Widowed" <?php echo (isset($_POST['married_status']) && $_POST['married_status'] === 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Ethnicity</label>
                                <input type="text" name="ethnicity" id="ethnicity" value="<?php echo isset($_POST['ethnicity']) ? htmlspecialchars($_POST['ethnicity']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="Enter ethnicity">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Religion</label>
                            <select name="religion" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                <option value="">Select Religion</option>
                                <?php foreach (get_nrc_religion_options() as $val => $label): ?>
                                    <option value="<?php echo $val; ?>" <?php echo (isset($_POST['religion']) && $_POST['religion'] === $val) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Phone Number</label>
                                <input type="text" name="phone" id="phone" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="Enter phone number">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Allowance</label>
                                <input type="number" name="allwance" id="allowance" value="<?php echo isset($_POST['allwance']) ? htmlspecialchars($_POST['allwance']) : ''; ?>" step="0.01" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="0.00">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Permanent Address</label>
                            <textarea name="permanent_address" id="permanent_address" rows="3" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="Enter permanent address"><?php echo isset($_POST['permanent_address']) ? htmlspecialchars($_POST['permanent_address']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Login, Company and Financial Cards -->
                <div class="space-y-6">

                    <!-- Account Login Card -->
                    <div class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200">
                        <div class="rounded-t-2xl border-b border-white/[0.06] bg-white/[0.04] px-6 py-4 text-white font-semibold shadow-sm">
                            <i class="fa-solid fa-lock text-violet-400 mr-2"></i>Account Login
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Email <span class="text-rose-500">*</span></label>
                                <input type="email" name="email" id="emp_email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="Enter email address">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Password <span class="text-rose-500">*</span></label>
                                <input type="password" name="password" id="emp_password" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="Enter password">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Confirm Password <span class="text-rose-500">*</span></label>
                                <input type="password" name="confirm_password" id="confirm_password" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="Confirm password">
                            </div>
                        </div>
                    </div>

                    <!-- Company Details Card -->
                    <div class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200">
                        <div class="rounded-t-2xl border-b border-white/[0.06] bg-white/[0.04] px-6 py-4 text-white font-semibold shadow-sm">
                            <i class="fa-solid fa-building text-violet-400 mr-2"></i>Company Details
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Employee ID</label>
                                <input type="text" value="Auto Generated" disabled class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-zinc-500 shadow-sm cursor-not-allowed opacity-60">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Department <span class="text-rose-500">*</span></label>
                                <select name="department_id" id="department" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">(Select A Department)</option>
                                    <?php
                                    if ($result_dept && mysqli_num_rows($result_dept) > 0) {
                                        while ($row = mysqli_fetch_assoc($result_dept)) {
                                            $dept_id   = htmlspecialchars($row['id']);
                                            $dept_name = htmlspecialchars($row['department_name']);
                                            $selected  = (isset($_POST['department_id']) && $_POST['department_id'] == $dept_id) ? 'selected' : '';
                                            echo '<option value="' . $dept_id . '" ' . $selected . '>' . $dept_name . '</option>';
                                        }
                                    } else {
                                        echo '<option value="">No Departments Found</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Designation <span class="text-rose-500">*</span></label>
                                <select name="position_id" id="designation" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">(Select A Designation)</option>
                                    <?php
                                    if ($result_opt && mysqli_num_rows($result_opt) > 0) {
                                        while ($row = mysqli_fetch_assoc($result_opt)) {
                                            $opt_id   = htmlspecialchars($row['id']);
                                            $opt_name = htmlspecialchars($row['position_name']);
                                            $selected  = (isset($_POST['position_id']) && $_POST['position_id'] == $opt_id) ? 'selected' : '';
                                            echo '<option value="' . $opt_id . '" ' . $selected . '>' . $opt_name . '</option>';
                                        }
                                    } else {
                                        echo '<option value="">No Departments Found</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="grid gap-4 lg:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Date Of Joining</label>
                                    <input type="date" name="doj" value="<?php echo isset($_POST['doj']) ? htmlspecialchars($_POST['doj']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Status</label>
                                    <select name="status" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                        <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Details Card -->
                    <div class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200">
                        <div class="rounded-t-2xl border-b border-white/[0.06] bg-white/[0.04] px-6 py-4 text-white font-semibold shadow-sm">
                            <i class="fa-solid fa-coins text-violet-400 mr-2"></i>Financial Details
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Basic Salary</label>
                                <input type="number" name="basic_salary" id="basic_salary" value="<?php echo isset($_POST['basic_salary']) ? htmlspecialchars($_POST['basic_salary']) : ''; ?>" step="0.01" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30" placeholder="0.00">
                            </div>
                            <div class="pt-2">
                                <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-3.5 shadow-lg shadow-violet-600/20 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-save"></i> Save Employee Information
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </form>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> HNIN AKARI NWE</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
<script>
// NRC Township data
const nrcTownships = <?php echo json_encode(get_nrc_township_codes()); ?>;

function populateTownship(state) {
    const sel = document.getElementById('nrc_township');
    sel.innerHTML = '<option value="">Township</option>';
    if (state && nrcTownships[state]) {
        nrcTownships[state].forEach(function(code) {
            const opt = document.createElement('option');
            opt.value = code; opt.textContent = code;
            sel.appendChild(opt);
        });
    }
}

function updateNrcPreview() {
    const state = document.getElementById('nrc_state').value;
    const township = document.getElementById('nrc_township').value;
    const citizenship = document.getElementById('nrc_citizenship').value;
    const number = document.getElementById('nrc_number').value;
    const preview = document.getElementById('nrc-preview');
    const previewVal = document.getElementById('nrc-preview-value');
    if (state && township && citizenship && number) {
        preview.classList.remove('hidden');
        previewVal.textContent = state + '/' + township + '(' + citizenship + ')' + number.padStart(6, '0');
    } else {
        preview.classList.add('hidden');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const stateSel = document.getElementById('nrc_state');
    const townshipSel = document.getElementById('nrc_township');
    const citizenshipSel = document.getElementById('nrc_citizenship');
    const numberInp = document.getElementById('nrc_number');

    stateSel.addEventListener('change', function() {
        populateTownship(this.value);
        updateNrcPreview();
    });
    townshipSel.addEventListener('change', updateNrcPreview);
    citizenshipSel.addEventListener('change', updateNrcPreview);
    numberInp.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
        updateNrcPreview();
    });

    // Restore state on page reload (form validation)
    <?php if (isset($_POST['nrc_state'])): ?>
    populateTownship('<?php echo $_POST['nrc_state']; ?>');
    <?php if (isset($_POST['nrc_township'])): ?>
    townshipSel.value = '<?php echo $_POST['nrc_township']; ?>';
    <?php endif; ?>
    updateNrcPreview();
    <?php endif; ?>
});
</script>
</body>

</html>
<?php
// 3. CLOSE DATABASE AT THE VERY END
mysqli_close($conn);
?>
