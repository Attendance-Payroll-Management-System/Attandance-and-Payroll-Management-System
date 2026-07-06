<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$message = '';
$message_type = '';

// Fetch departments and positions for dropdowns
$dept_result = mysqli_query($conn, "SELECT id, department_name FROM departments");
$pos_result = mysqli_query($conn, "SELECT id, position_name FROM positions");

$employee_id = 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) { http_response_code(403); exit('CSRF validation failed.'); }
    $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;

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
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "UPDATE employee SET department_id = ?, position_id = ?, name = ?, gender = ?, dob = ?, phone = ?, email = ?, password = ?, hire_date = ?, basic_salary = ?, status = ?, role = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $role = $designation ?: 'Employee';
                $stmt->bind_param("ssssssssssssi", $department_id, $position_id, $name, $gender, $dob, $phone, $email, $hashed_password, $doj, $basic_salary, $status, $role, $employee_id);
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

// Parse existing NRC for pre-filling dropdowns
[$nrc_state_val, $nrc_township_val, $nrc_citizenship_val, $nrc_number_val] = parse_nrc($emp['nrc'] ?? '');
if (empty($nrc_state_val)) { $nrc_state_val = ''; }
if (empty($nrc_township_val)) { $nrc_township_val = ''; }
if (empty($nrc_citizenship_val)) { $nrc_citizenship_val = 'N'; }
if (empty($nrc_number_val)) { $nrc_number_val = ''; }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Edit <?php echo htmlspecialchars($emp['name']); ?></title>
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Edit Employee"; $page_subtitle = "Update employee information and details."; $page_actions = '<span class="inline-flex items-center gap-2 rounded-full bg-card-custom px-3 py-1 border border-body text-sm text-body-secondary"><i class="fa-solid fa-id-badge text-violet-400"></i> ' . htmlspecialchars($emp['employee_code']) . '</span>'; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type === 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo $message; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <form action="edit_employee.php" method="POST" class="grid grid-cols-1 xl:grid-cols-[1.6fr_1fr] gap-6 items-stretch">
            <?php echo csrf_field(); ?>
                <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">

                <!-- Left Column: Personal Details Card -->
                <div class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200">
                    <div class="rounded-t-2xl border-b border-white/[0.06] bg-white/[0.04] px-6 py-4 text-white font-semibold shadow-sm">
                        <i class="fa-solid fa-user text-violet-400 mr-2"></i>Personal Details
                    </div>
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($emp['name']); ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Father Name</label>
                            <input type="text" name="father_name" value="<?php echo htmlspecialchars($emp['father_name'] ?: ''); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Date Of Birth <span class="text-rose-500">*</span></label>
                                <input type="date" name="dob" value="<?php echo htmlspecialchars($emp['dob'] ?: ''); ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Gender</label>
                                <select name="gender" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo ($emp['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo ($emp['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo ($emp['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">National Registration Card</label>
                            <div class="grid grid-cols-12 gap-2" id="nrc-container">
                                <div class="col-span-3">
                                    <select name="nrc_state" id="nrc_state" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-2 py-3 text-sm text-white outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                        <option value="">State</option>
                                        <?php foreach (get_nrc_state_codes() as $val => $label): ?>
                                            <option value="<?php echo $val; ?>" <?php echo ($nrc_state_val === $val) ? 'selected' : ''; ?>><?php echo $label; ?></option>
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
                                            <option value="<?php echo $val; ?>" <?php echo ($nrc_citizenship_val === $val) ? 'selected' : ''; ?>><?php echo $val; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-span-2">
                                    <input type="text" name="nrc_number" id="nrc_number" value="<?php echo htmlspecialchars($nrc_number_val); ?>" maxlength="6" placeholder="123456" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-2 py-3 text-sm text-white placeholder-zinc-500 outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                </div>
                            </div>
                            <div id="nrc-preview" class="mt-2 text-xs text-zinc-400 <?php echo $nrc_state_val ? '' : 'hidden'; ?>">
                                NRC: <span id="nrc-preview-value" class="text-violet-400 font-mono font-semibold"><?php echo htmlspecialchars($emp['nrc'] ?: ''); ?></span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Married Status</label>
                            <input type="text" name="married_status" value="<?php echo htmlspecialchars($emp['married_status'] ?: ''); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Ethnicity</label>
                            <input type="text" name="ethnicity" value="<?php echo htmlspecialchars($emp['ethnicity'] ?: ''); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Religion</label>
                            <select name="religion" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                <option value="">Select Religion</option>
                                <?php foreach (get_nrc_religion_options() as $val => $label): ?>
                                    <option value="<?php echo $val; ?>" <?php echo (($emp['religion'] ?? '') === $val) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Phone Number</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($emp['phone'] ?: ''); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Permanent Address</label>
                            <textarea name="permanent_address" rows="3" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30"><?php echo htmlspecialchars($emp['permanent_address'] ?: ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Account, Company, Financial -->
                <div class="space-y-6">

                    <!-- Account Login Card -->
                    <div class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200">
                        <div class="rounded-t-2xl border-b border-white/[0.06] bg-white/[0.04] px-6 py-4 text-white font-semibold shadow-sm">
                            <i class="fa-solid fa-lock text-violet-400 mr-2"></i>Account Login
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Email <span class="text-rose-500">*</span></label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($emp['email']); ?>" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">New Password <span class="text-slate-400">(leave blank to keep current)</span></label>
                                <input type="password" name="password" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
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
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Employee Code</label>
                                <input type="text" value="<?php echo htmlspecialchars($emp['employee_code']); ?>" disabled class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-zinc-500 shadow-sm cursor-not-allowed">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Department <span class="text-red-500">*</span></label>
                                <select name="department_id" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
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
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Designation</label>
                                <select name="position_id" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
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
                                    <label class="block text-xs font-semibold text-zinc-400 mb-2">Date Of Joining</label>
                                    <input type="date" name="doj" value="<?php echo htmlspecialchars($emp['hire_date'] ?: ''); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-400 mb-2">Status</label>
                                    <select name="status" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                        <option value="active" <?php echo ($emp['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ($emp['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Role</label>
                                <input type="text" name="designation" value="<?php echo htmlspecialchars($emp['role'] ?: ''); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
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
                                <input type="number" name="basic_salary" value="<?php echo htmlspecialchars($emp['basic_salary'] ?: '0'); ?>" step="0.01" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-2">Allowance</label>
                                <input type="number" name="allowance" value="<?php echo htmlspecialchars($emp['allowance'] ?: '0'); ?>" step="0.01" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div class="flex gap-3">
                                <button type="submit" class="flex-1 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-save"></i> Update Employee
                                </button>
                                <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="flex-1 rounded-xl border border-white/10 glass-strong hover:bg-white/[0.06] text-zinc-300 font-semibold text-sm px-5 py-3 text-center transition flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </form>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> AURA HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
<script>
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

    <?php if ($nrc_state_val): ?>
    populateTownship('<?php echo $nrc_state_val; ?>');
    townshipSel.value = '<?php echo $nrc_township_val; ?>';
    updateNrcPreview();
    <?php endif; ?>
});
</script>
</body>

</html>
<?php
mysqli_close($conn);
?>
