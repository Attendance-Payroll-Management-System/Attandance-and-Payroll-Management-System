<?php
session_start();
require_once "../config/db.php";

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT id, email, password, name FROM employee WHERE email=?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            $stored = $user['password'];
            $is_hashed = strlen($stored) === 60 && strpos($stored, '$2y$') === 0;

            if (!$is_hashed) {
                $error = "Invalid email or password";
            } else {
                $valid = password_verify($password, $stored);

                if ($valid) {
                    session_regenerate_id(true);
                    $_SESSION['logged_in'] = true;
                    $_SESSION['employee_id'] = $user['id'];
                    $_SESSION['employee_name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    header('Location: attendance.php');
                    exit;
                } else {
                    $error = "Invalid email or password";
                }
            }
        } else {
            $error = "Invalid email or password";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Employee Login</title>
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
</head>
<body class="min-h-screen bg-body text-body overflow-hidden">

    <!-- Background -->
    <div class="fixed inset-0 bg-grid pointer-events-none"></div>
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-96 h-96 bg-sky-500/8 rounded-full blur-3xl animate-breathe"></div>
        <div class="absolute -bottom-40 -left-40 w-96 h-96 bg-cyan-500/8 rounded-full blur-3xl animate-breathe" style="animation-delay: 2s;"></div>
        <div class="absolute top-1/4 left-1/4 w-32 h-32 border border-sky-500/10 rounded-full animate-spin-slow"></div>
        <div class="absolute bottom-1/4 right-1/4 w-24 h-24 border border-cyan-500/10 rounded-full animate-spin-slow" style="animation-direction: reverse;"></div>
    </div>

    <!-- Theme Toggle -->
    <div class="fixed top-4 right-4 z-50">
        <button onclick="toggleTheme()" class="theme-toggle-btn">
            <i class="fa-solid fa-sun icon-sun text-base"></i>
            <i class="fa-solid fa-moon icon-moon text-base"></i>
        </button>
    </div>

    <!-- Main Login Container -->
    <div class="min-h-screen flex items-center justify-center px-4 py-8 relative z-10">

        <!-- Login Card -->
        <div class="w-full max-w-4xl flex flex-col lg:flex-row bg-white dark:bg-[#0F172A] rounded-3xl shadow-2xl shadow-slate-200/50 dark:shadow-black/30 overflow-hidden border border-slate-200 dark:border-white/[0.06] animate-fade-in-up">

            <!-- Left Panel — Illustration -->
            <div class="hidden lg:flex lg:w-1/2 relative overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-br from-sky-500 via-blue-600 to-cyan-600"></div>
                <div class="absolute inset-0 opacity-10">
                    <div class="absolute top-10 left-10 w-20 h-20 border border-white/30 rounded-2xl rotate-12 animate-float"></div>
                    <div class="absolute top-1/3 right-16 w-16 h-16 border border-white/20 rounded-full animate-float" style="animation-delay: 1s;"></div>
                    <div class="absolute bottom-20 left-20 w-24 h-24 border border-white/20 rounded-2xl -rotate-12 animate-float" style="animation-delay: 2s;"></div>
                    <div class="absolute bottom-1/3 right-10 w-12 h-12 border border-white/15 rounded-xl rotate-45 animate-float" style="animation-delay: 3s;"></div>
                </div>
                <div class="relative z-10 flex flex-col items-center justify-center p-12 text-white w-full">
                    <!-- SVG Illustration — People/Team -->
                    <svg class="w-56 h-56 mb-8 drop-shadow-2xl" viewBox="0 0 400 400" fill="none">
                        <!-- Main Circle -->
                        <circle cx="200" cy="200" r="140" fill="rgba(255,255,255,0.06)" stroke="rgba(255,255,255,0.2)" stroke-width="2"/>
                        <circle cx="200" cy="200" r="110" fill="rgba(255,255,255,0.04)" stroke="rgba(255,255,255,0.12)" stroke-width="1.5"/>
                        <!-- People Icons -->
                        <circle cx="160" cy="170" r="20" fill="rgba(255,255,255,0.15)" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
                        <path d="M130 230C130 210 143 195 160 195C177 195 190 210 190 230" stroke="rgba(255,255,255,0.4)" stroke-width="2" stroke-linecap="round" fill="none"/>
                        <circle cx="240" cy="170" r="20" fill="rgba(255,255,255,0.15)" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
                        <path d="M210 230C210 210 223 195 240 195C257 195 270 210 270 230" stroke="rgba(255,255,255,0.4)" stroke-width="2" stroke-linecap="round" fill="none"/>
                        <!-- Connection Lines -->
                        <line x1="180" y1="170" x2="220" y2="170" stroke="rgba(255,255,255,0.2)" stroke-width="1.5" stroke-dasharray="4 4"/>
                        <!-- Clock -->
                        <circle cx="200" cy="280" r="18" fill="rgba(255,255,255,0.1)" stroke="rgba(255,255,255,0.3)" stroke-width="1.5"/>
                        <line x1="200" y1="280" x2="200" y2="270" stroke="rgba(255,255,255,0.6)" stroke-width="2" stroke-linecap="round"/>
                        <line x1="200" y1="280" x2="210" y2="280" stroke="rgba(255,255,255,0.5)" stroke-width="1.5" stroke-linecap="round"/>
                        <!-- Orbiting Dots -->
                        <circle cx="200" cy="60" r="4" fill="rgba(255,255,255,0.6)"><animateTransform attributeName="transform" type="rotate" from="0 200 200" to="360 200 200" dur="12s" repeatCount="indefinite"/></circle>
                        <circle cx="340" cy="200" r="3" fill="rgba(255,255,255,0.4)"><animateTransform attributeName="transform" type="rotate" from="120 200 200" to="480 200 200" dur="15s" repeatCount="indefinite"/></circle>
                        <circle cx="60" cy="200" r="3" fill="rgba(255,255,255,0.4)"><animateTransform attributeName="transform" type="rotate" from="240 200 200" to="600 200 200" dur="18s" repeatCount="indefinite"/></circle>
                    </svg>
                    <h2 class="text-2xl font-bold mb-2 text-center">HNIN AKARI NWE</h2>
                    <p class="text-white/70 text-sm text-center max-w-xs">Your daily attendance, leave requests, and payroll — all in one place.</p>
                    <div class="mt-8 flex items-center gap-6 text-white/50 text-xs">
                        <span class="flex items-center gap-1.5"><i class="fa-solid fa-calendar-check"></i> Attendance</span>
                        <span class="flex items-center gap-1.5"><i class="fa-solid fa-paper-plane"></i> Leave</span>
                        <span class="flex items-center gap-1.5"><i class="fa-solid fa-wallet"></i> Payroll</span>
                    </div>
                </div>
            </div>

            <!-- Right Panel — Login Form -->
            <div class="w-full lg:w-1/2 p-8 sm:p-10 flex flex-col justify-center">
                <!-- Mobile Logo -->
                    <div class="flex flex-col items-center gap-4 mb-8 lg:hidden animate-fade-in-up">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-sky-500 to-blue-500 flex items-center justify-center shadow-2xl shadow-sky-500/20 animate-float ring-2 ring-sky-500/20">
                        <i class="fas fa-bolt text-white text-2xl"></i>
                    </div>
                    <div class="text-center">
                        <h1 class="text-2xl font-bold tracking-tight text-body">Welcome Back</h1>
                        <p class="text-body-secondary text-sm">Sign in to your employee account</p>
                    </div>
                </div>

                <!-- Desktop Title -->
                <div class="hidden lg:block mb-8 animate-fade-in-up">
                    <h1 class="text-2xl font-bold tracking-tight text-body">Welcome back</h1>
                    <p class="text-body-secondary text-sm mt-1">Sign in to access your dashboard</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="flex items-center gap-3 p-3.5 bg-red-50 dark:bg-red-500/10 rounded-xl ring-1 ring-red-200 dark:ring-red-500/20 text-red-600 dark:text-red-400 text-sm font-medium animate-slide-down mb-6">
                        <i class="fas fa-circle-exclamation text-red-500 dark:text-red-400"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="flex items-center gap-3 p-3.5 bg-emerald-50 dark:bg-emerald-500/10 rounded-xl ring-1 ring-emerald-200 dark:ring-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-sm font-medium animate-slide-down mb-6">
                        <i class="fas fa-check-circle text-emerald-500 dark:text-emerald-400"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="space-y-5">
                    <div class="space-y-1.5">
                        <label for="email" class="text-sm font-medium text-body-secondary">
                            <i class="fa-regular fa-envelope mr-1.5 text-sky-500"></i>Email
                        </label>
                        <input type="email" id="email" name="email" placeholder="you@company.com"
                            class="w-full px-4 py-3 rounded-xl bg-slate-50 dark:bg-white/[0.05] border border-slate-200 dark:border-white/10 text-body placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/30 focus:border-sky-500 transition-all duration-200" />
                    </div>
                    <div class="space-y-1.5">
                        <label for="password" class="text-sm font-medium text-body-secondary">
                            <i class="fa-solid fa-lock mr-1.5 text-sky-500"></i>Password
                        </label>
                        <div class="relative">
                            <input type="password" id="password" name="password" placeholder="Enter your password"
                                class="w-full px-4 py-3 pr-11 rounded-xl bg-slate-50 dark:bg-white/[0.05] border border-slate-200 dark:border-white/10 text-body placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/30 focus:border-sky-500 transition-all duration-200">
                            <span class="pw-eye-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-sky-500 cursor-pointer select-none z-10" role="button" tabindex="0" data-target="password">
                                <i class="fa-solid fa-eye text-base"></i>
                            </span>
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full bg-gradient-to-r from-sky-500 to-blue-500 hover:from-sky-400 hover:to-blue-400 text-white font-semibold px-4 py-3.5 rounded-xl shadow-lg shadow-sky-500/25 hover:shadow-xl hover:shadow-sky-500/30 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 relative overflow-hidden">
                        <span class="relative z-10"><i class="fa-solid fa-right-to-bracket mr-2"></i> Sign In</span>
                    </button>

                    <div class="flex items-center justify-between text-xs">
                        <a href="forgot_password.php" class="text-body-muted hover:text-sky-500 font-medium transition-colors"><i class="fa-solid fa-key mr-1"></i> Forgot Password?</a>
                        <a href="../admin/login.php" class="text-body-muted hover:text-sky-500 font-medium transition-colors"><i class="fa-solid fa-shield-halved mr-1"></i> Admin Login</a>
                    </div>
                </form>

                <div class="flex items-center justify-center gap-3 mt-8">
                    <span class="h-px w-12 bg-slate-200 dark:bg-white/10"></span>
                    <a href="../home/home.php" class="text-sm text-body-muted hover:text-sky-500 font-medium transition-colors"><i class="fa-solid fa-arrow-left mr-1"></i> Back to Home</a>
                    <span class="h-px w-12 bg-slate-200 dark:bg-white/10"></span>
                </div>

                <p class="text-center text-[10px] text-body-muted mt-6">
                    <i class="fa-solid fa-shield-halved mr-1 text-[8px]"></i>Secured with AES-256 encryption
                </p>
            </div>
        </div>
    </div>

<script>
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
