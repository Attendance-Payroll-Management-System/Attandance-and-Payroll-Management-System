<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';

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
        <?php $page_title = "Add Employee"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type === 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo $message; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <header class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl font-bold text-body tracking-tight">Add New Employee</h1>
                    <p class="text-sm text-body-secondary mt-1">Create a new employee record with personal, company, and financial details.</p>
                </div>
                <div class="flex items-center gap-2 text-sm text-body-secondary">
                    <span class="inline-flex items-center gap-2 rounded-full bg-card-custom px-3 py-1 border border-body"><i class="fa-solid fa-calendar-days text-violet-400"></i> <?php echo date('d M Y'); ?></span>
                </div>
            </header>

            <!-- FORM START -->
            <form action="insert1.php" method="POST" class="grid grid-cols-1 xl:grid-cols-[1.6fr_1fr] gap-6 items-stretch">

                <!-- Left Column: Personal Details Card -->
                <div class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200">
                    <div class="rounded-t-2xl border-b border-white/[0.06] bg-white/[0.04] px-6 py-4 text-white font-semibold shadow-sm">
                        <i class="fa-solid fa-user text-violet-400 mr-2"></i>Personal Details
                    </div>
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Father Name</label>
                            <input type="text" name="father_name" value="<?php echo isset($_POST['father_name']) ? htmlspecialchars($_POST['father_name']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Date Of Birth <span class="text-rose-500">*</span></label>
                                <input type="date" name="dob" value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Gender</label>
                                <select name="gender" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">National Registration Card</label>
                            <input type="text" name="nrc" value="<?php echo isset($_POST['nrc']) ? htmlspecialchars($_POST['nrc']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Married Status</label>
                            <input type="text" name="married_status" value="<?php echo isset($_POST['married_status']) ? htmlspecialchars($_POST['married_status']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Ethnicity</label>
                            <input type="text" name="ethnicity" value="<?php echo isset($_POST['ethnicity']) ? htmlspecialchars($_POST['ethnicity']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Religion</label>
                            <input type="text" name="religion" value="<?php echo isset($_POST['religion']) ? htmlspecialchars($_POST['religion']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Phone Number</label>
                            <input type="text" name="phone" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Permanent Address</label>
                            <textarea name="permanent_address" rows="3" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30"><?php echo isset($_POST['permanent_address']) ? htmlspecialchars($_POST['permanent_address']) : ''; ?></textarea>
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
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Email <span class="text-rose-500">*</span></label>
                                <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Password <span class="text-rose-500">*</span></label>
                                <input type="password" name="password" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Confirm Password <span class="text-rose-500">*</span></label>
                                <input type="password" name="confirm_password" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
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
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Employee Id</label>
                                <input type="text" value="Auto Generated" disabled class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-zinc-500 shadow-sm cursor-not-allowed">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Department <span class="text-red-500">*</span></label>
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
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Designation <span class="text-red-500">*</span></label>
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
                                    <label class="block text-xs font-semibold text-zinc-400 mb-2">Date Of Joining</label>
                                    <input type="date" name="doj" value="<?php echo isset($_POST['doj']) ? htmlspecialchars($_POST['doj']) : ''; ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-2">Status</label>
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
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Basic Salary</label>
                                <input type="number" name="basic_salary" value="<?php echo isset($_POST['basic_salary']) ? htmlspecialchars($_POST['basic_salary']) : ''; ?>" step="0.01" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Allowance</label>
                                <input type="number" name="allwance" value="<?php echo isset($_POST['allwance']) ? htmlspecialchars($_POST['allwance']) : ''; ?>" step="0.01" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-save"></i> Save Employee Information
                            </button>
                        </div>
                    </div>

                </div>
            </form>
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
<?php
// 3. CLOSE DATABASE AT THE VERY END
mysqli_close($conn);
?>
