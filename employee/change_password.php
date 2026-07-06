<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'];
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) { $message = "Invalid request."; $message_type = "error"; } else {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $message = 'Please fill in all fields.';
        $message_type = 'error';
    } elseif ($new_password !== $confirm_password) {
        $message = 'New passwords do not match.';
        $message_type = 'error';
    } elseif (strlen($new_password) < 6) {
        $message = 'New password must be at least 6 characters.';
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("SELECT password FROM employee WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            $stored = $user['password'];
            $is_hashed = strlen($stored) === 60 && strpos($stored, '$2y$') === 0;

            if (!$is_hashed) {
                $message = 'Current password is incorrect.';
                $message_type = 'error';
            } else {
                $valid = password_verify($current_password, $stored);

                if (!$valid) {
                    $message = 'Current password is incorrect.';
                    $message_type = 'error';
                } else {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE employee SET password = ? WHERE id = ?");
                $update->bind_param("si", $hashed, $employee_id);
                if ($update->execute()) {
                    $message = 'Password changed successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error updating password.';
                    $message_type = 'error';
                }
                $update->close();
            }
        }
    }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Change Password</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased flex h-screen overflow-hidden">

    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col h-full overflow-y-auto lg:ml-64">
        <header class="glass-strong px-8 py-4 flex items-center justify-between shrink-0">
            <div class="animate-fade-in-up">
                <h2 class="text-xl font-bold text-white">Change Password</h2>
                <p class="text-xs text-zinc-400"><?php echo date('l, F j, Y'); ?></p>
            </div>
            <button onclick="toggleTheme()" class="theme-toggle-btn">
                <i class="fa-solid fa-sun icon-sun text-base"></i>
                <i class="fa-solid fa-moon icon-moon text-base"></i>
            </button>
            <div class="flex items-center gap-3 border-l border-white/10 pl-4">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-bold shadow-inner"><?php echo strtoupper(substr($employee_name, 0, 2)); ?></div>
                <div class="text-right">
                    <h4 class="text-sm font-semibold text-white"><?php echo htmlspecialchars($employee_name); ?></h4>
                    <span class="text-xs text-zinc-400">Employee</span>
                </div>
            </div>
        </header>

        <main class="p-8 flex-1 max-w-[1400px] w-full mx-auto">
            <?php if ($message): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border <?php echo $message_type == 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="max-w-lg mx-auto">
                <div class="card-hover group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-5">
                    <div class="border-b border-white/[0.06] px-6 py-4">
                        <h3 class="font-bold text-white"><i class="fa-solid fa-lock mr-2 text-blue-400"></i>Update Your Password</h3>
                    </div>
                    <form method="POST" class="p-6 space-y-5">
                    <?php echo csrf_field(); ?>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Current Password</label>
                            <input type="password" name="current_password" required
                                class="w-full rounded-lg border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">New Password</label>
                            <input type="password" name="new_password" required
                                class="w-full rounded-lg border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-2">Confirm New Password</label>
                            <input type="password" name="confirm_password" required
                                class="w-full rounded-lg border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20">
                        </div>
                        <button type="submit"
                            class="w-full rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-3 transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-save"></i> Change Password
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>

</html>
