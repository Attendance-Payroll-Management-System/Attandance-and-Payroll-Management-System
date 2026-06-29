<?php
require_once '../config/db.php';

$message = '';
$message_type = '';

// Fetch departments and positions for dropdowns
$dept_result = mysqli_query($conn, "SELECT id, department_name FROM departments");
$pos_result = mysqli_query($conn, "SELECT id, position_name FROM positions");

$employee_id = 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;

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
    $department_id = isset($_POST['department_id']) ? $_POST['department_id'] : null;
    $position_id = isset($_POST['position_id']) ? $_POST['position_id'] : null;
    $designation = isset($_POST['designation']) ? htmlspecialchars(trim($_POST['designation'])) : '';
    $doj = isset($_POST['doj']) ? $_POST['doj'] : '';
    $status = isset($_POST['status']) ? $_POST['status'] : 'active';
    $basic_salary = isset($_POST['basic_salary']) ? $_POST['basic_salary'] : 0;
    $allowance = isset($_POST['allowance']) ? $_POST['allowance'] : 0;
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($name) || empty($email) || empty($dob) || empty($department_id)) {
        $message = 'Please fill in all required fields!';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format!';
        $message_type = 'error';
    } else {
        // Check if email exists for another employee
        $check_email = $conn->prepare("SELECT id FROM employee WHERE email = ? AND id != ?");
        $check_email->bind_param("si", $email, $employee_id);
        $check_email->execute();
        $check_email->store_result();

        if ($check_email->num_rows > 0) {
            $message = 'Email already exists for another employee!';
            $message_type = 'error';
        } else {
            // Update employee table
            if (!empty($password)) {
                $sql = "UPDATE employee SET department_id = ?, position_id = ?, name = ?, gender = ?, dob = ?, phone = ?, email = ?, password = ?, hire_date = ?, basic_salary = ?, status = ?, role = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $role = $designation ?: 'Employee';
                $stmt->bind_param("ssssssssssssi", $department_id, $position_id, $name, $gender, $dob, $phone, $email, $password, $doj, $basic_salary, $status, $role, $employee_id);
            } else {
                $sql = "UPDATE employee SET department_id = ?, position_id = ?, name = ?, gender = ?, dob = ?, phone = ?, email = ?, hire_date = ?, basic_salary = ?, status = ?, role = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $role = $designation ?: 'Employee';
                $stmt->bind_param("sssssssssssi", $department_id, $position_id, $name, $gender, $dob, $phone, $email, $doj, $basic_salary, $status, $role, $employee_id);
            }

            if ($stmt->execute()) {
                // Update or insert employee_personal_info
                $check_personal = $conn->prepare("SELECT id FROM employee_personal_info WHERE employee_id = ?");
                $check_personal->bind_param("i", $employee_id);
                $check_personal->execute();
                $check_personal->store_result();

                if ($check_personal->num_rows > 0) {
                    $sql_personal = "UPDATE employee_personal_info SET father_name = ?, nrc = ?, married_status = ?, ethnicity = ?, religion = ?, permanent_address = ?, allowance = ? WHERE employee_id = ?";
                    $stmt_personal = $conn->prepare($sql_personal);
                    $stmt_personal->bind_param("ssssssdi", $father_name, $nrc, $married_status, $ethnicity, $religion, $permanent_address, $allowance, $employee_id);
                } else {
                    $sql_personal = "INSERT INTO employee_personal_info (employee_id, father_name, nrc, married_status, ethnicity, religion, permanent_address, allowance) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_personal = $conn->prepare($sql_personal);
                    $stmt_personal->bind_param("issssssd", $employee_id, $father_name, $nrc, $married_status, $ethnicity, $religion, $permanent_address, $allowance);
                }

                if ($stmt_personal->execute()) {
                    $message = 'Employee updated successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error updating personal details: ' . $stmt_personal->error;
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
} else {
    $employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
}

if ($employee_id <= 0) {
    header('Location: employee.php');
    exit;
}

// Fetch employee data
$stmt = $conn->prepare("SELECT
    e.*,
    epi.father_name,
    epi.nrc,
    epi.married_status,
    epi.ethnicity,
    epi.religion,
    epi.permanent_address,
    epi.allowance
FROM employee e
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
    <title>Edit Employee - <?php echo htmlspecialchars($emp['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
</head>

<body class="min-h-screen antialiased font-sans text-slate-700 bg-[radial-gradient(circle_at_top_left,_rgba(52,211,153,0.16),_transparent_18%),radial-gradient(circle_at_top_right,_rgba(56,189,248,0.12),_transparent_22%),linear-gradient(180deg,_#f8fafc_0%,_#e2e8f0_100%)]">

    <header class="bg-white/80 backdrop-blur-lg shadow-sm flex items-center justify-between px-6 py-3 fixed top-0 w-full z-10 h-14">
        <div class="flex items-center space-x-4">
            <a href="employee.php" class="text-slate-600 hover:text-slate-900 focus:outline-none">
                <i class="fa-solid fa-arrow-left text-xl"></i>
            </a>
            <div class="text-sm font-semibold text-slate-700">Edit Employee</div>
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
        <aside class="w-72 rounded-3xl border border-slate-200 bg-slate-900/95 text-slate-300 shadow-xl shadow-slate-900/10 backdrop-blur-xl p-4">
            <div class="rounded-3xl bg-slate-800/90 p-5 text-center border border-slate-700/60 shadow-inner">
                <div class="text-emerald-400 font-bold text-xl uppercase tracking-[0.2em]">Payroll</div>
                <div class="text-xs text-slate-500 mt-1">Attendance Management</div>
            </div>
            <div class="mt-5 rounded-3xl border border-slate-700/70 bg-slate-900/80 p-4 shadow-inner">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-emerald-500/15 text-emerald-400 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase tracking-[0.2em]">Editing</p>
                        <p class="text-sm font-semibold text-white"><?php echo htmlspecialchars($emp['name']); ?></p>
                    </div>
                </div>
            </div>
            <nav class="mt-6 space-y-2">
                <a href="dashboard.php" class="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-gauge w-5"></i> Dashboard
                </a>
                <a href="employee.php" class="flex items-center gap-3 rounded-2xl bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-300 shadow-inner">
                    <i class="fa-solid fa-users w-5"></i> Employees
                </a>
                <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-eye w-5"></i> View Profile
                </a>
                <a href="payroll.php" class="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-slate-200 transition hover:text-white hover:bg-emerald-200/20">
                    <i class="fa-solid fa-credit-card w-5"></i> Payroll
                </a>
            </nav>
        </aside>

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
                        <p class="text-sm uppercase tracking-[0.3em] text-emerald-500">Employee Management</p>
                        <h1 class="text-3xl font-semibold text-slate-900">Edit Employee</h1>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm text-slate-500">
                        <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1">
                            <i class="fa-solid fa-id-badge text-emerald-500"></i> <?php echo htmlspecialchars($emp['employee_code']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <form action="edit_employee.php" method="POST" class="grid grid-cols-1 xl:grid-cols-[1.6fr_1fr] gap-6 items-stretch">
                <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">

                <!-- Left Column: Personal Details Card -->
                <div class="rounded-3xl border border-slate-200/80 bg-white/95 shadow-xl shadow-slate-900/5 backdrop-blur-xl">
                    <div class="rounded-t-3xl border-b border-slate-200/80 bg-slate-50 px-6 py-4 text-slate-900 font-semibold shadow-sm">
                        Personal Details
                    </div>
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($emp['name']); ?>" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Father Name</label>
                            <input type="text" name="father_name" value="<?php echo htmlspecialchars($emp['father_name'] ?: ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Date Of Birth <span class="text-rose-500">*</span></label>
                                <input type="date" name="dob" value="<?php echo htmlspecialchars($emp['dob'] ?: ''); ?>" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Gender</label>
                                <select name="gender" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo ($emp['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo ($emp['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo ($emp['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">National Registration Card</label>
                            <input type="text" name="nrc" value="<?php echo htmlspecialchars($emp['nrc'] ?: ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Married Status</label>
                            <input type="text" name="married_status" value="<?php echo htmlspecialchars($emp['married_status'] ?: ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Ethnicity</label>
                            <input type="text" name="ethnicity" value="<?php echo htmlspecialchars($emp['ethnicity'] ?: ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Religion</label>
                            <input type="text" name="religion" value="<?php echo htmlspecialchars($emp['religion'] ?: ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Phone Number</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($emp['phone'] ?: ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-2">Permanent Address</label>
                            <textarea name="permanent_address" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200"><?php echo htmlspecialchars($emp['permanent_address'] ?: ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Account, Company, Financial -->
                <div class="space-y-6">

                    <!-- Account Login Card -->
                    <div class="rounded-3xl border border-slate-200/80 bg-white/95 shadow-xl shadow-slate-900/5 backdrop-blur-xl">
                        <div class="rounded-t-3xl bg-gradient-to-r from-teal-700 to-sky-500 px-6 py-4 text-white font-semibold shadow-sm">
                            Account Login
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Email <span class="text-rose-500">*</span></label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($emp['email']); ?>" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">New Password <span class="text-slate-400">(leave blank to keep current)</span></label>
                                <input type="password" name="password" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
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
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Employee Code</label>
                                <input type="text" value="<?php echo htmlspecialchars($emp['employee_code']); ?>" disabled class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500 shadow-sm cursor-not-allowed">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Department <span class="text-red-500">*</span></label>
                                <select name="department_id" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                    <option value="">(Select A Department)</option>
                                    <?php
                                    if ($dept_result && mysqli_num_rows($dept_result) > 0) {
                                        mysqli_data_seek($dept_result, 0);
                                        while ($row = mysqli_fetch_assoc($dept_result)) {
                                            $selected = ($emp['department_id'] == $row['id']) ? 'selected' : '';
                                            echo '<option value="' . htmlspecialchars($row['id']) . '" ' . $selected . '>' . htmlspecialchars($row['department_name']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Designation</label>
                                <select name="position_id" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                    <option value="">(Select A Designation)</option>
                                    <?php
                                    if ($pos_result && mysqli_num_rows($pos_result) > 0) {
                                        mysqli_data_seek($pos_result, 0);
                                        while ($row = mysqli_fetch_assoc($pos_result)) {
                                            $selected = ($emp['position_id'] == $row['id']) ? 'selected' : '';
                                            echo '<option value="' . htmlspecialchars($row['id']) . '" ' . $selected . '>' . htmlspecialchars($row['position_name']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="grid gap-4 lg:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-2">Date Of Joining</label>
                                    <input type="date" name="doj" value="<?php echo htmlspecialchars($emp['hire_date'] ?: ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-2">Status</label>
                                    <select name="status" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                                        <option value="active" <?php echo ($emp['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ($emp['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Role</label>
                                <input type="text" name="designation" value="<?php echo htmlspecialchars($emp['role'] ?: ''); ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
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
                                <input type="number" name="basic_salary" value="<?php echo htmlspecialchars($emp['basic_salary'] ?: '0'); ?>" step="0.01" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-2">Allowance</label>
                                <input type="number" name="allowance" value="<?php echo htmlspecialchars($emp['allowance'] ?: '0'); ?>" step="0.01" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200">
                            </div>
                            <div class="flex gap-3">
                                <button type="submit" class="flex-1 rounded-2xl bg-emerald-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:ring-offset-2">
                                    <i class="fa-solid fa-save mr-2"></i> Update Employee
                                </button>
                                <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="flex-1 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-600 text-center transition hover:bg-slate-50">
                                    <i class="fa-solid fa-times mr-2"></i> Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </form>
        </main>
    </div>
</body>

</html>
<?php
mysqli_close($conn);
?>