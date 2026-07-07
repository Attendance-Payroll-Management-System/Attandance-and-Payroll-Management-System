<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";

$message = '';
$message_type = '';
$valid_token = false;
$employee_id = 0;
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = trim($_GET['token']);

    $stmt = $conn->prepare("SELECT pr.id, pr.employee_id, e.email FROM password_resets pr JOIN employee e ON pr.employee_id = e.id WHERE pr.token = ? AND e.email = ? AND pr.used = 0 AND pr.expires_at > NOW()");
    $stmt->bind_param("ss", $token, $email);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result) {
        $valid_token = true;
        $employee_id = $result['employee_id'];
    } else {
        $message = "This reset link is invalid or has expired.";
        $message_type = "error";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset'])) {
    $token = trim($_POST['token']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (empty($password) || strlen($password) < 6) {
        $message = "Password must be at least 6 characters.";
        $message_type = "error";
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
        $message_type = "error";
    } else {
        $stmt = $conn->prepare("SELECT pr.id, pr.employee_id, pr.expires_at, pr.used FROM password_resets pr JOIN employee e ON pr.employee_id = e.id WHERE pr.token = ? AND e.email = ? AND pr.used = 0 AND pr.expires_at > NOW()");
        $stmt->bind_param("ss", $token, $email);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE employee SET password = ? WHERE id = ?");
            $update->bind_param("si", $hashed, $result['employee_id']);
            $update->execute();
            $update->close();

            $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
            $stmt->bind_param("i", $result['id']);
            $stmt->execute();
            $stmt->close();

            $message = "Password reset successfully! You can now log in with your new password.";
            $message_type = "success";
            $valid_token = false;
        } else {
            $message = "Invalid or expired reset link.";
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Reset Password</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body class="min-h-screen flex flex-col items-center justify-center bg-grid px-4 bg-body text-body overflow-hidden">
    <div class="fixed top-4 right-4 z-50">
        <button onclick="toggleTheme()" class="theme-toggle-btn">
            <i class="fa-solid fa-sun icon-sun text-base"></i>
            <i class="fa-solid fa-moon icon-moon text-base"></i>
        </button>
    </div>
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-96 h-96 bg-violet-500/10 rounded-full blur-3xl animate-breathe"></div>
        <div class="absolute -bottom-40 -left-40 w-96 h-96 bg-fuchsia-500/10 rounded-full blur-3xl animate-breathe" style="animation-delay: 2s;"></div>
        <div class="absolute top-20 left-20 w-32 h-32 border border-violet-500/10 rounded-full animate-spin-slow"></div>
        <div class="absolute bottom-20 right-20 w-24 h-24 border border-fuchsia-500/10 rounded-full animate-spin-slow" style="animation-direction: reverse;"></div>
        <svg class="absolute bottom-1/4 right-1/4 w-20 h-20 text-fuchsia-500/5 animate-float" viewBox="0 0 24 24" fill="currentColor"><path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>
    </div>

    <div class="w-full max-w-[420px] space-y-6 relative z-10">
        <div class="flex flex-col items-center gap-4 animate-fade-in-up">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 via-fuchsia-500 to-amber-500 flex items-center justify-center shadow-2xl shadow-violet-500/20 animate-float ring-2 ring-white/20 glow-violet card-inner-glow">
                <i class="fas fa-lock-open text-white text-2xl"></i>
            </div>
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-body">Reset Password</h1>
                <p class="text-body-secondary text-sm">Choose a new password for your account</p>
            </div>
        </div>

        <div class="border-gradient text-body w-full flex flex-col p-8 gap-6 rounded-2xl animate-fade-in-up stagger-1">
            <div class="glass-strong text-white w-full flex flex-col gap-6 -m-[1px] p-8 rounded-2xl" style="background: var(--glass-strong-bg);">
            <?php if ($message): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl text-sm font-medium animate-slide-down <?php echo $message_type == 'success' ? 'bg-emerald-500/10 ring-1 ring-emerald-500/20 text-emerald-400' : 'bg-red-500/10 ring-1 ring-red-500/20 text-red-400'; ?>">
                    <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($valid_token): ?>
            <form method="POST" class="space-y-5">
            <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <div class="space-y-1.5">
                    <label class="text-sm font-medium text-zinc-300"><i class="fa-solid fa-lock mr-1.5 text-violet-400"></i>New Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="reset-new-pw" placeholder="Min. 6 characters" required minlength="6" class="w-full px-4 py-3 pr-11 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                        <span class="pw-eye-toggle absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-violet-400 cursor-pointer select-none z-10" role="button" tabindex="0" data-target="reset-new-pw">
                            <i class="fa-solid fa-eye text-base"></i>
                        </span>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-sm font-medium text-zinc-300"><i class="fa-solid fa-check-circle mr-1.5 text-violet-400"></i>Confirm Password</label>
                    <div class="relative">
                        <input type="password" name="confirm_password" id="reset-confirm-pw" placeholder="Repeat new password" required class="w-full px-4 py-3 pr-11 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                        <span class="pw-eye-toggle absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-violet-400 cursor-pointer select-none z-10" role="button" tabindex="0" data-target="reset-confirm-pw">
                            <i class="fa-solid fa-eye text-base"></i>
                        </span>
                    </div>
                </div>
                <button type="submit" name="reset" value="1" class="w-full bg-gradient-to-r from-violet-600 via-fuchsia-600 to-amber-600 hover:from-violet-500 hover:via-fuchsia-500 hover:to-amber-500 text-white font-semibold px-4 py-3.5 rounded-xl shadow-lg shadow-violet-600/20 hover:shadow-xl transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 animate-gradient btn-hover-lift relative overflow-hidden">
                    <span class="relative z-10"><i class="fa-solid fa-check mr-2"></i> Reset Password</span>
                    <span class="absolute inset-0 bg-white/10 opacity-0 hover:opacity-100 transition-opacity"></span>
                </button>
            </form>
            <?php endif; ?>

            <div class="text-center">
                <a href="login.php" class="text-violet-400 hover:text-violet-300 font-semibold text-sm transition-colors"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Login</a>
            </div>
            </div>
        </div>

        <p class="text-center text-[10px] text-body-muted animate-fade-in-up stagger-2">
            <i class="fa-solid fa-shield-halved mr-1 text-[8px]"></i>Secure password reset with 256-bit encryption
        </p>
    </div>
<script>
function toggleTheme() {
    var html = document.documentElement;
    var isDark = html.classList.contains('dark');
    if (isDark) {
        html.classList.remove('dark');
        localStorage.setItem('aura-theme', 'light');
    } else {
        html.classList.add('dark');
        localStorage.setItem('aura-theme', 'dark');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.pw-eye-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            var input = document.getElementById(toggle.getAttribute('data-target'));
            var icon = toggle.querySelector('i');
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text'; icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password'; icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');
            }
        });
    });
});
</script>
</body>
</html>
