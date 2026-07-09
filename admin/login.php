<?php
session_start();
require_once "../config/db.php";

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT id, email, password, name FROM employee WHERE email=? AND (role='Admin' OR role='admin' OR id=1)");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            $stored = $user['password'];
            $is_hashed = strlen($stored) === 60 && strpos($stored, '$2y$') === 0;

            if (!$is_hashed) {
                $error = "Invalid credentials";
            } else {
                $valid = password_verify($password, $stored);

                if ($valid) {
                    session_regenerate_id(true);
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_name'] = $user['name'];
                    $_SESSION['admin_email'] = $user['email'];
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = "Invalid credentials";
                }
            }
        } else {
            $error = "Invalid credentials";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Admin Login</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <link rel="stylesheet" href="../assets/css/app.css">
    <script>
    (function() {
        var theme = localStorage.getItem('aura-theme');
        if (theme === 'light') {
            document.documentElement.classList.remove('dark');
        } else if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
                document.documentElement.classList.remove('dark');
            } else {
                document.documentElement.classList.add('dark');
            }
        }
    })();
    </script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
    :root:not(.dark) .glass-strong.text-white,
    :root:not(.dark) .glass-strong .text-white { color: #0f172a !important; }
    :root:not(.dark) .glass-strong.text-zinc-400,
    :root:not(.dark) .glass-strong.text-zinc-300,
    :root:not(.dark) .glass-strong .text-zinc-400,
    :root:not(.dark) .glass-strong .text-zinc-300 { color: #475569 !important; }
    :root:not(.dark) .glass-strong.text-zinc-500,
    :root:not(.dark) .glass-strong .text-zinc-500 { color: #94a3b8 !important; }
    :root:not(.dark) .glass-strong.bg-white\/\[0\.06\],
    :root:not(.dark) .glass-strong.bg-white\/10,
    :root:not(.dark) .glass-strong.bg-white\/\[0\.04\],
    :root:not(.dark) .glass-strong .bg-white\/\[0\.06\],
    :root:not(.dark) .glass-strong .bg-white\/10,
    :root:not(.dark) .glass-strong .bg-white\/\[0\.04\] { background-color: #f1f5f9 !important; }
    :root:not(.dark) .glass-strong.border-white\/10,
    :root:not(.dark) .glass-strong.border-white\/\[0\.06\],
    :root:not(.dark) .glass-strong .border-white\/10,
    :root:not(.dark) .glass-strong .border-white\/\[0\.06\] { border-color: #e2e8f0 !important; }
    :root:not(.dark) .glass-strong button.text-white { color: #ffffff !important; }
    :root:not(.dark) .glass-strong button.hover\:text-white:hover { color: #475569 !important; }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center bg-grid px-4 bg-body text-body overflow-hidden">
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl animate-breathe"></div>
        <div class="absolute -bottom-40 -left-40 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl animate-breathe" style="animation-delay: 2s;"></div>
        <div class="absolute top-1/2 -translate-y-1/2 -left-20 w-60 h-60 bg-amber-500/5 rounded-full blur-3xl animate-breathe" style="animation-delay: 4s;"></div>
        <div class="absolute top-20 right-20 w-32 h-32 border border-blue-500/10 rounded-full animate-spin-slow"></div>
        <div class="absolute bottom-20 left-20 w-24 h-24 border border-indigo-500/10 rounded-full animate-spin-slow" style="animation-direction: reverse;"></div>
        <svg class="absolute top-1/4 left-1/4 w-16 h-16 text-blue-500/5 animate-float" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        <svg class="absolute bottom-1/4 right-1/4 w-20 h-20 text-indigo-500/5 animate-float" style="animation-delay: 2s;" viewBox="0 0 24 24" fill="currentColor"><path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>
    </div>

    <div class="fixed top-4 right-4 z-50">
        <button onclick="toggleTheme()" class="theme-toggle-btn">
            <i class="fa-solid fa-sun icon-sun text-base"></i>
            <i class="fa-solid fa-moon icon-moon text-base"></i>
        </button>
    </div>

    <div class="w-full max-w-[420px] space-y-6 relative z-10">
        <div class="flex flex-col items-center gap-4 animate-fade-in-up">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-600 via-indigo-600 to-blue-500 flex items-center justify-center shadow-2xl shadow-blue-500/20 animate-float ring-2 ring-white/20 glow-navy card-inner-glow">
                <i class="fas fa-shield-halved text-white text-2xl"></i>
            </div>
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-body">Admin Portal</h1>
                <p class="text-body-secondary text-sm">Secure authentication required</p>
            </div>
        </div>

        <div class="border-gradient text-body w-full flex flex-col p-8 gap-6 rounded-2xl animate-fade-in-up stagger-1">
            <div class="glass-strong text-white w-full flex flex-col gap-6 -m-[1px] p-8 rounded-2xl" style="background: var(--glass-strong-bg);">
            <?php if ($error): ?>
                <div class="flex items-center gap-3 p-3 bg-red-500/10 rounded-xl ring-1 ring-red-500/20 text-red-400 text-sm font-medium animate-slide-down">
                    <i class="fas fa-circle-exclamation text-red-400"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-5">
                <div class="space-y-1.5">
                    <label class="text-sm font-medium text-zinc-300"><i class="fa-regular fa-envelope mr-1.5 text-blue-400"></i>Email / Username</label>
                    <input type="text" name="username" placeholder="admin@company.com" class="w-full px-4 py-3 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500/50 transition-all duration-200">
                </div>
                <div class="space-y-1.5">
                    <label class="text-sm font-medium text-zinc-300"><i class="fa-solid fa-lock mr-1.5 text-blue-400"></i>Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="admin-login-password" placeholder="Enter password" class="w-full px-4 py-3 pr-11 rounded-xl bg-white/[0.06] border border-white/10 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500/50 transition-all duration-200">
                        <span class="pw-eye-toggle absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-blue-400 cursor-pointer select-none z-10" role="button" tabindex="0" data-target="admin-login-password">
                            <i class="fa-solid fa-eye text-base"></i>
                        </span>
                    </div>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-blue-700 via-indigo-600 to-blue-600 hover:from-blue-600 hover:via-indigo-500 hover:to-blue-500 text-white font-semibold px-4 py-3.5 rounded-xl shadow-lg shadow-blue-600/20 hover:shadow-xl hover:shadow-blue-500/30 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 animate-gradient btn-hover-lift relative overflow-hidden">
                    <span class="relative z-10"><i class="fa-solid fa-lock-open mr-2"></i> Authenticate</span>
                    <span class="absolute inset-0 bg-white/10 opacity-0 hover:opacity-100 transition-opacity"></span>
                </button>
            </form>
            </div>
        </div>

        <div class="flex items-center justify-center gap-3 animate-fade-in-up stagger-2">
            <span class="h-px w-12 bg-body-secondary"></span>
            <a href="../employee/login.php" class="text-sm text-body-muted hover:text-blue-400 font-medium transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-arrow-left text-xs"></i> Employee Login
            </a>
            <span class="h-px w-12 bg-body-secondary"></span>
        </div>

        <p class="text-center text-[10px] text-body-muted animate-fade-in-up stagger-3">
            <i class="fa-solid fa-shield-halved mr-1 text-[8px]"></i>Secured with AES-256 encryption
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
