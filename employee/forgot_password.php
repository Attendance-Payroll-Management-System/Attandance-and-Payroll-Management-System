<?php
session_start();
require_once "../config/db.php";

$message = '';
$message_type = '';
$email_sent = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $message = "Please enter your email address.";
        $message_type = "error";
    } else {
        $stmt = $conn->prepare("SELECT id, name FROM employee WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt = $conn->prepare("INSERT INTO password_resets (employee_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user['id'], $token, $expires);
            $stmt->execute();
            $stmt->close();

            $reset_link = "http://{$_SERVER['HTTP_HOST']}/employee/reset_password.php?token=$token&email=" . urlencode($email);
            $message = "Password reset link sent to your email. <br><small class='text-zinc-400'>(Demo: <a href='" . htmlspecialchars($reset_link) . "' class='text-violet-400 underline'>Click here to reset</a>)</small>";
            $message_type = "success";
            $email_sent = true;
        } else {
            $message = "No account found with that email address.";
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
    <title>AURA HR · Forgot Password</title>
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
        <svg class="absolute top-1/4 left-1/4 w-16 h-16 text-violet-500/5 animate-float" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
    </div>

    <div class="w-full max-w-[420px] space-y-6 relative z-10">
        <div class="flex flex-col items-center gap-4 animate-fade-in-up">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 via-fuchsia-500 to-amber-500 flex items-center justify-center shadow-2xl shadow-violet-500/20 animate-float ring-2 ring-white/20 glow-violet card-inner-glow">
                <i class="fas fa-key text-white text-2xl"></i>
            </div>
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-body">Forgot Password</h1>
                <p class="text-body-secondary text-sm">Enter your email to receive a reset link</p>
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

            <?php if (!$email_sent): ?>
            <form method="POST" class="space-y-5">
                <div class="space-y-1.5">
                    <label class="text-sm font-medium text-zinc-300"><i class="fa-regular fa-envelope mr-1.5 text-violet-400"></i>Email Address</label>
                    <input type="email" name="email" placeholder="you@company.com" required class="w-full px-4 py-3 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 transition-all">
                </div>
                <button type="submit" class="w-full bg-gradient-to-r from-violet-600 via-fuchsia-600 to-amber-600 hover:from-violet-500 hover:via-fuchsia-500 hover:to-amber-500 text-white font-semibold px-4 py-3.5 rounded-xl shadow-lg shadow-violet-600/20 hover:shadow-xl transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 animate-gradient btn-hover-lift relative overflow-hidden">
                    <span class="relative z-10"><i class="fa-solid fa-paper-plane mr-2"></i> Send Reset Link</span>
                    <span class="absolute inset-0 bg-white/10 opacity-0 hover:opacity-100 transition-opacity"></span>
                </button>
            </form>
            <?php else: ?>
            <div class="text-center">
                <a href="login.php" class="inline-block bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white font-semibold px-6 py-3.5 rounded-xl shadow-lg transition-all hover:-translate-y-0.5 active:translate-y-0 btn-hover-lift">
                    <i class="fa-solid fa-arrow-left mr-2"></i> Back to Login
                </a>
            </div>
            <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center justify-center gap-3 animate-fade-in-up stagger-2">
            <span class="h-px w-12 bg-body-secondary"></span>
            <a href="login.php" class="text-sm text-body-muted hover:text-violet-400 font-medium transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-arrow-left text-xs"></i> Back to Login
            </a>
            <span class="h-px w-12 bg-body-secondary"></span>
        </div>

        <p class="text-center text-[10px] text-body-muted animate-fade-in-up stagger-3">
            <i class="fa-solid fa-shield-halved mr-1 text-[8px]"></i>Your data is encrypted in transit
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
</script>
</body>
</html>
