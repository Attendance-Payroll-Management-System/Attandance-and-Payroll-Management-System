<?php
session_start();
require_once '../config/db.php';
require_once '../config/helpers.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$message = '';
$message_type = '';

// Fetch admin data
$has_photo_col = $conn->query("SHOW COLUMNS FROM employee LIKE 'profile_photo'");
$has_photo = $has_photo_col && $has_photo_col->num_rows > 0;

if ($has_photo) {
    $stmt = $conn->prepare("SELECT * FROM employee WHERE id = ?");
} else {
    $stmt = $conn->prepare("SELECT id, employee_code, name, email, phone, gender, dob, department_id, position_id, status, hire_date, basic_salary FROM employee WHERE id = ?");
}
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    header('Location: dashboard.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Update Profile
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($full_name) || empty($email)) {
            $message = 'Name and email are required.';
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("UPDATE employee SET name = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->bind_param("sssi", $full_name, $email, $phone, $admin_id);
            if ($stmt->execute()) {
                $_SESSION['admin_name'] = $full_name;
                $_SESSION['admin_email'] = $email;
                $admin_name = $full_name;
                $admin['name'] = $full_name;
                $admin['email'] = $email;
                $admin['phone'] = $phone;
                $message = 'Profile updated successfully!';
                $message_type = 'success';
            } else {
                $message = 'Failed to update profile.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    }

    // Change Password
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $message = 'All password fields are required.';
            $message_type = 'error';
        } elseif ($new_password !== $confirm_password) {
            $message = 'New passwords do not match.';
            $message_type = 'error';
        } elseif (strlen($new_password) < 6) {
            $message = 'Password must be at least 6 characters.';
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("SELECT password FROM employee WHERE id = ?");
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($current_password, $user['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE employee SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $admin_id);
                if ($stmt->execute()) {
                    $message = 'Password changed successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to change password.';
                    $message_type = 'error';
                }
                $stmt->close();
            } else {
                $message = 'Current password is incorrect.';
                $message_type = 'error';
            }
        }
    }

    // Upload Profile Photo
    if ($action === 'upload_photo') {
        if (!$has_photo) {
            // Add the column first
            $conn->query("ALTER TABLE employee ADD COLUMN profile_photo VARCHAR(255) NULL AFTER status");
            $has_photo = true;
        }

        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_photo'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($file['type'], $allowed_types)) {
                $message = 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP';
                $message_type = 'error';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $message = 'File size must be less than 5MB.';
                $message_type = 'error';
            } else {
                $upload_dir = '../assets/uploads/profile/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $filename = 'admin_' . $admin_id . '_' . time() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
                $filepath = $upload_dir . $filename;

                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Delete old photo if exists
                    if (!empty($admin['profile_photo']) && file_exists('../' . $admin['profile_photo'])) {
                        unlink('../' . $admin['profile_photo']);
                    }

                    $relative_path = 'assets/uploads/profile/' . $filename;
                    $stmt = $conn->prepare("UPDATE employee SET profile_photo = ? WHERE id = ?");
                    $stmt->bind_param("si", $relative_path, $admin_id);
                    if ($stmt->execute()) {
                        $admin['profile_photo'] = $relative_path;
                        $message = 'Profile photo updated successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to save photo.';
                        $message_type = 'error';
                    }
                    $stmt->close();
                } else {
                    $message = 'Failed to upload file.';
                    $message_type = 'error';
                }
            }
        } else {
            $message = 'Please select a photo to upload.';
            $message_type = 'error';
        }
    }
}

// Get department and position info
$dept_name = '';
$pos_name = '';
if ($admin['department_id']) {
    $res = $conn->query("SELECT department_name FROM departments WHERE id = " . (int)$admin['department_id']);
    if ($res && $row = $res->fetch_assoc()) $dept_name = $row['department_name'];
}
if ($admin['position_id']) {
    $res = $conn->query("SELECT position_name FROM positions WHERE id = " . (int)$admin['position_id']);
    if ($res && $row = $res->fetch_assoc()) $pos_name = $row['position_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false, activeTab: 'profile' }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php include "../includes/topbar.php"; ?>

        <main class="flex-1 p-6 lg:p-8 overflow-y-auto">

            <?php if ($message): ?>
            <div class="mb-6 px-4 py-3 rounded-lg border <?php echo $message_type === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?> animate-fade-in-up">
                <div class="flex items-center gap-2">
                    <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Profile Header Card -->
            <div class="glass-strong rounded-2xl overflow-hidden mb-6 animate-fade-in-up">
                <div class="h-32 bg-gradient-to-r from-violet-600 via-fuchsia-600 to-amber-500 relative">
                    <div class="absolute inset-0 bg-black/10"></div>
                </div>
                <div class="px-6 pb-6 relative">
                    <div class="flex flex-col sm:flex-row items-center sm:items-end gap-4 -mt-12">
                        <!-- Profile Photo -->
                        <div class="relative group">
                            <?php if (!empty($admin['profile_photo'])): ?>
                            <img src="../<?php echo htmlspecialchars($admin['profile_photo']); ?>" alt="Profile" class="w-24 h-24 rounded-full border-4 border-white dark:border-[#18181b] object-cover shadow-lg">
                            <?php else: ?>
                            <div class="w-24 h-24 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-3xl font-bold border-4 border-white dark:border-[#18181b] shadow-lg">
                                <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                            <label for="photo-upload" class="absolute bottom-0 right-0 w-8 h-8 bg-violet-600 rounded-full flex items-center justify-center text-white text-xs cursor-pointer hover:bg-violet-700 transition shadow-lg opacity-0 group-hover:opacity-100">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                            <input type="file" id="photo-upload" name="profile_photo" class="hidden" accept="image/*" onchange="document.getElementById('photo-form').submit();">
                        </div>
                        <form id="photo-form" method="POST" enctype="multipart/form-data" class="hidden">
                            <input type="hidden" name="action" value="upload_photo">
                        </form>

                        <div class="flex-1 text-center sm:text-left pb-2">
                            <h1 class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($admin['name']); ?></h1>
                            <p class="text-sm text-slate-500 dark:text-zinc-400"><?php echo htmlspecialchars($admin['email']); ?></p>
                            <div class="flex flex-wrap justify-center sm:justify-start gap-2 mt-2">
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-violet-500/20 text-violet-400">
                                    <i class="fa-solid fa-shield-halved mr-1"></i>Administrator
                                </span>
                                <?php if ($admin['status'] === 'active'): ?>
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">
                                    <i class="fa-solid fa-circle-check mr-1"></i>Active
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
                <button @click="activeTab = 'profile'" :class="activeTab === 'profile' ? 'bg-violet-600 text-white' : 'glass-strong text-slate-600 dark:text-zinc-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    <i class="fa-solid fa-user-pen mr-2"></i>Edit Profile
                </button>
                <button @click="activeTab = 'password'" :class="activeTab === 'password' ? 'bg-violet-600 text-white' : 'glass-strong text-slate-600 dark:text-zinc-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    <i class="fa-solid fa-lock mr-2"></i>Change Password
                </button>
                <button @click="activeTab = 'account'" :class="activeTab === 'account' ? 'bg-violet-600 text-white' : 'glass-strong text-slate-600 dark:text-zinc-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    <i class="fa-solid fa-circle-info mr-2"></i>Account Info
                </button>
                <button @click="activeTab = 'settings'" :class="activeTab === 'settings' ? 'bg-violet-600 text-white' : 'glass-strong text-slate-600 dark:text-zinc-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap">
                    <i class="fa-solid fa-gear mr-2"></i>Account Settings
                </button>
            </div>

            <!-- Edit Profile Tab -->
            <div x-show="activeTab === 'profile'" x-transition>
                <form method="POST" class="glass-strong rounded-2xl p-6 animate-fade-in-up">
                    <input type="hidden" name="action" value="update_profile">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">
                        <i class="fa-solid fa-user-pen text-violet-400 mr-2"></i>Personal Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">Full Name *</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($admin['name']); ?>" required
                                class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/[0.06] px-4 py-2.5 text-sm text-slate-900 dark:text-white outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">Email Address *</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required
                                class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/[0.06] px-4 py-2.5 text-sm text-slate-900 dark:text-white outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">Phone Number</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>"
                                class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/[0.06] px-4 py-2.5 text-sm text-slate-900 dark:text-white outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">Gender</label>
                            <select name="gender" class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/[0.06] px-4 py-2.5 text-sm text-slate-900 dark:text-white outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20">
                                <option value="Male" <?php echo ($admin['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($admin['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">Date of Birth</label>
                            <input type="date" name="dob" value="<?php echo htmlspecialchars($admin['dob'] ?? ''); ?>"
                                class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/[0.06] px-4 py-2.5 text-sm text-slate-900 dark:text-white outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">Employee Code</label>
                            <input type="text" value="<?php echo htmlspecialchars($admin['employee_code']); ?>" disabled
                                class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-100 dark:bg-white/[0.03] px-4 py-2.5 text-sm text-slate-500 dark:text-zinc-500 cursor-not-allowed">
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="px-6 py-2.5 bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold rounded-lg transition-all">
                            <i class="fa-solid fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password Tab -->
            <div x-show="activeTab === 'password'" x-transition style="display: none;">
                <form method="POST" class="glass-strong rounded-2xl p-6 animate-fade-in-up">
                    <input type="hidden" name="action" value="change_password">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">
                        <i class="fa-solid fa-lock text-violet-400 mr-2"></i>Change Password
                    </h3>
                    <div class="max-w-md space-y-5">
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">Current Password</label>
                            <div class="relative">
                                <input type="password" name="current_password" id="admin-profile-current-pw" required
                                    class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/[0.06] px-4 py-2.5 pr-11 text-sm text-slate-900 dark:text-white outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20">
                                <span class="pw-eye-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-400 hover:text-violet-500 dark:hover:text-violet-400 cursor-pointer select-none z-10" role="button" tabindex="0" data-target="admin-profile-current-pw">
                                    <i class="fa-solid fa-eye text-base"></i>
                                </span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">New Password</label>
                            <div class="relative">
                                <input type="password" name="new_password" id="admin-profile-new-pw" required minlength="6"
                                    class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/[0.06] px-4 py-2.5 pr-11 text-sm text-slate-900 dark:text-white outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20">
                                <span class="pw-eye-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-400 hover:text-violet-500 dark:hover:text-violet-400 cursor-pointer select-none z-10" role="button" tabindex="0" data-target="admin-profile-new-pw">
                                    <i class="fa-solid fa-eye text-base"></i>
                                </span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 dark:text-zinc-400 mb-2">Confirm New Password</label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="admin-profile-confirm-pw" required minlength="6"
                                    class="w-full rounded-lg border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-white/[0.06] px-4 py-2.5 pr-11 text-sm text-slate-900 dark:text-white outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20">
                                <span class="pw-eye-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-400 hover:text-violet-500 dark:hover:text-violet-400 cursor-pointer select-none z-10" role="button" tabindex="0" data-target="admin-profile-confirm-pw">
                                    <i class="fa-solid fa-eye text-base"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="px-6 py-2.5 bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold rounded-lg transition-all">
                            <i class="fa-solid fa-key mr-2"></i>Update Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- Account Info Tab -->
            <div x-show="activeTab === 'account'" x-transition style="display: none;">
                <div class="glass-strong rounded-2xl p-6 animate-fade-in-up">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">
                        <i class="fa-solid fa-circle-info text-violet-400 mr-2"></i>Account Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <div class="flex justify-between py-2 border-b border-slate-200 dark:border-white/[0.06]">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Employee Code</span>
                                <span class="text-sm font-medium text-slate-900 dark:text-white"><?php echo htmlspecialchars($admin['employee_code']); ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-slate-200 dark:border-white/[0.06]">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Role</span>
                                <span class="text-sm font-medium text-slate-900 dark:text-white">Administrator</span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-slate-200 dark:border-white/[0.06]">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Department</span>
                                <span class="text-sm font-medium text-slate-900 dark:text-white"><?php echo htmlspecialchars($dept_name ?: 'N/A'); ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-slate-200 dark:border-white/[0.06]">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Position</span>
                                <span class="text-sm font-medium text-slate-900 dark:text-white"><?php echo htmlspecialchars($pos_name ?: 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div class="flex justify-between py-2 border-b border-slate-200 dark:border-white/[0.06]">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Status</span>
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $admin['status'] === 'active' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400'; ?>">
                                    <?php echo ucfirst($admin['status']); ?>
                                </span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-slate-200 dark:border-white/[0.06]">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Hire Date</span>
                                <span class="text-sm font-medium text-slate-900 dark:text-white"><?php echo $admin['hire_date'] ? date('M d, Y', strtotime($admin['hire_date'])) : 'N/A'; ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-slate-200 dark:border-white/[0.06]">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Basic Salary</span>
                                <span class="text-sm font-medium text-emerald-400">$<?php echo number_format($admin['basic_salary'] ?? 0, 2); ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-slate-200 dark:border-white/[0.06]">
                                <span class="text-sm text-slate-500 dark:text-zinc-400">Phone</span>
                                <span class="text-sm font-medium text-slate-900 dark:text-white"><?php echo htmlspecialchars($admin['phone'] ?: 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Settings Tab -->
            <div x-show="activeTab === 'settings'" x-transition style="display: none;">
                <div class="space-y-6">
                    <!-- Theme Settings -->
                    <div class="glass-strong rounded-2xl p-6 animate-fade-in-up">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">
                            <i class="fa-solid fa-palette text-violet-400 mr-2"></i>Appearance
                        </h3>
                        <div class="flex items-center justify-between p-4 rounded-lg bg-slate-50 dark:bg-white/[0.02] border border-slate-200 dark:border-white/[0.06]">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-lg bg-violet-500/20 flex items-center justify-center">
                                    <i class="fa-solid fa-circle-half-stroke text-violet-400"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Dark Mode</p>
                                    <p class="text-xs text-slate-500 dark:text-zinc-400">Switch between light and dark theme</p>
                                </div>
                            </div>
                            <button onclick="toggleTheme()" class="px-4 py-2 rounded-lg bg-slate-200 dark:bg-white/10 text-slate-700 dark:text-zinc-300 text-sm font-medium hover:bg-slate-300 dark:hover:bg-white/20 transition">
                                <i class="fa-solid fa-repeat mr-1"></i>Toggle
                            </button>
                        </div>
                    </div>

                    <!-- Notification Settings -->
                    <div class="glass-strong rounded-2xl p-6 animate-fade-in-up">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">
                            <i class="fa-solid fa-bell text-violet-400 mr-2"></i>Notifications
                        </h3>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-4 rounded-lg bg-slate-50 dark:bg-white/[0.02] border border-slate-200 dark:border-white/[0.06]">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                                        <i class="fa-solid fa-envelope text-emerald-400"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white">Leave Requests</p>
                                        <p class="text-xs text-slate-500 dark:text-zinc-400">Get notified for leave approvals</p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">Enabled</span>
                            </div>
                            <div class="flex items-center justify-between p-4 rounded-lg bg-slate-50 dark:bg-white/[0.02] border border-slate-200 dark:border-white/[0.06]">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-amber-500/20 flex items-center justify-center">
                                        <i class="fa-solid fa-clock text-amber-400"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white">Overtime Requests</p>
                                        <p class="text-xs text-slate-500 dark:text-zinc-400">Get notified for overtime approvals</p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">Enabled</span>
                            </div>
                            <div class="flex items-center justify-between p-4 rounded-lg bg-slate-50 dark:bg-white/[0.02] border border-slate-200 dark:border-white/[0.06]">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-blue-500/20 flex items-center justify-center">
                                        <i class="fa-solid fa-user-plus text-blue-400"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white">New Employees</p>
                                        <p class="text-xs text-slate-500 dark:text-zinc-400">Get notified for new registrations</p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">Enabled</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div class="glass-strong rounded-2xl p-6 animate-fade-in-up">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">
                            <i class="fa-solid fa-link text-violet-400 mr-2"></i>Quick Links
                        </h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <a href="settings.php" class="flex items-center gap-3 p-4 rounded-lg bg-slate-50 dark:bg-white/[0.02] border border-slate-200 dark:border-white/[0.06] hover:bg-slate-100 dark:hover:bg-white/[0.04] transition">
                                <div class="w-10 h-10 rounded-lg bg-violet-500/20 flex items-center justify-center">
                                    <i class="fa-solid fa-gear text-violet-400"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">System Settings</p>
                                    <p class="text-xs text-slate-500 dark:text-zinc-400">Configure system preferences</p>
                                </div>
                            </a>
                            <a href="employee.php" class="flex items-center gap-3 p-4 rounded-lg bg-slate-50 dark:bg-white/[0.02] border border-slate-200 dark:border-white/[0.06] hover:bg-slate-100 dark:hover:bg-white/[0.04] transition">
                                <div class="w-10 h-10 rounded-lg bg-blue-500/20 flex items-center justify-center">
                                    <i class="fa-solid fa-users text-blue-400"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Manage Employees</p>
                                    <p class="text-xs text-slate-500 dark:text-zinc-400">View and manage staff</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
