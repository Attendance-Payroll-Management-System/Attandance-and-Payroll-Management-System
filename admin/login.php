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
        $stmt = $conn->prepare("SELECT e.id, e.email, e.password, e.name, d.department_name, p.position_name FROM employee e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN positions p ON e.position_id = p.id WHERE e.email=? AND (e.role='Admin' OR e.role='admin' OR e.id=1)");
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
                    $_SESSION['admin_department'] = $user['department_name'] ?? '';
                    $_SESSION['admin_position'] = $user['position_name'] ?? '';
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
</head>
<body class="min-h-screen bg-body text-body overflow-hidden">

    <!-- Background Grid & Ambient Effects -->
    <div class="fixed inset-0 bg-grid pointer-events-none"></div>
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-96 h-96 bg-sky-500/8 rounded-full blur-3xl animate-breathe"></div>
        <div class="absolute -bottom-40 -left-40 w-96 h-96 bg-blue-500/8 rounded-full blur-3xl animate-breathe" style="animation-delay: 2s;"></div>
        <div class="absolute top-1/3 right-1/4 w-48 h-48 bg-cyan-500/5 rounded-full blur-3xl animate-breathe" style="animation-delay: 4s;"></div>
        <div class="absolute top-1/4 left-1/6 w-32 h-32 border border-sky-500/10 rounded-full animate-spin-slow"></div>
        <div class="absolute bottom-1/4 right-1/5 w-24 h-24 border border-blue-500/10 rounded-full animate-spin-slow" style="animation-direction: reverse;"></div>
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
                <div class="absolute inset-0 bg-gradient-to-br from-sky-500 via-blue-600 to-sky-700"></div>
                <div class="absolute inset-0 opacity-10">
                    <div class="absolute top-10 left-10 w-20 h-20 border border-white/30 rounded-2xl rotate-12 animate-float"></div>
                    <div class="absolute top-1/3 right-16 w-16 h-16 border border-white/20 rounded-full animate-float" style="animation-delay: 1s;"></div>
                    <div class="absolute bottom-20 left-20 w-24 h-24 border border-white/20 rounded-2xl -rotate-12 animate-float" style="animation-delay: 2s;"></div>
                    <div class="absolute bottom-1/3 right-10 w-12 h-12 border border-white/15 rounded-xl rotate-45 animate-float" style="animation-delay: 3s;"></div>
                </div>
                <div class="relative z-10 flex flex-col items-center justify-center p-12 text-white w-full">
                    <!-- SVG Illustration -->
                    <svg class="w-56 h-56 mb-8 drop-shadow-2xl" viewBox="0 0 400 400" fill="none">
                        <!-- Shield -->
                        <path d="M200 50L80 100V180C80 260 130 330 200 360C270 330 320 260 320 180V100L200 50Z" fill="rgba(255,255,255,0.1)" stroke="rgba(255,255,255,0.3)" stroke-width="2"/>
                        <path d="M200 80L100 120V185C100 250 140 310 200 335C260 310 300 250 300 185V120L200 80Z" fill="rgba(255,255,255,0.08)" stroke="rgba(255,255,255,0.2)" stroke-width="1.5"/>
                        <!-- Lock Icon -->
                        <rect x="170" y="175" width="60" height="50" rx="8" fill="rgba(255,255,255,0.2)" stroke="rgba(255,255,255,0.5)" stroke-width="2"/>
                        <path d="M183 175V160C183 151.716 189.716 145 198 145H202C210.284 145 217 151.716 217 160V175" stroke="rgba(255,255,255,0.5)" stroke-width="2.5" stroke-linecap="round"/>
                        <circle cx="200" cy="200" r="6" fill="rgba(255,255,255,0.8)"/>
                        <line x1="200" y1="206" x2="200" y2="214" stroke="rgba(255,255,255,0.6)" stroke-width="2" stroke-linecap="round"/>
                        <!-- Orbiting Dots -->
                        <circle cx="200" cy="50" r="4" fill="rgba(255,255,255,0.6)"><animateTransform attributeName="transform" type="rotate" from="0 200 200" to="360 200 200" dur="12s" repeatCount="indefinite"/></circle>
                        <circle cx="320" cy="180" r="3" fill="rgba(255,255,255,0.4)"><animateTransform attributeName="transform" type="rotate" from="120 200 200" to="480 200 200" dur="15s" repeatCount="indefinite"/></circle>
                        <circle cx="80" cy="180" r="3" fill="rgba(255,255,255,0.4)"><animateTransform attributeName="transform" type="rotate" from="240 200 200" to="600 200 200" dur="18s" repeatCount="indefinite"/></circle>
                    </svg>
                    <h2 class="text-2xl font-bold mb-2 text-center">HNIN AKARI NWE</h2>
                    <p class="text-white/70 text-sm text-center max-w-xs">Secure administration portal with enterprise-grade authentication and access controls.</p>
                    <div class="mt-8 flex items-center gap-6 text-white/50 text-xs">
                        <span class="flex items-center gap-1.5"><i class="fa-solid fa-shield-halved"></i> AES-256</span>
                        <span class="flex items-center gap-1.5"><i class="fa-solid fa-lock"></i> SSL/TLS</span>
                        <span class="flex items-center gap-1.5"><i class="fa-solid fa-fingerprint"></i> 2FA Ready</span>
                    </div>
                </div>
            </div>

            <!-- Right Panel — Login Form -->
            <div class="w-full lg:w-1/2 p-8 sm:p-10 flex flex-col justify-center">
                <!-- Mobile Logo -->
                    <div class="flex flex-col items-center gap-4 mb-8 lg:hidden animate-fade-in-up">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-sky-500 to-blue-600 flex items-center justify-center shadow-2xl shadow-sky-600/20 animate-float ring-2 ring-sky-500/20">
                        <i class="fas fa-shield-halved text-white text-2xl"></i>
                    </div>
                    <div class="text-center">
                        <h1 class="text-2xl font-bold tracking-tight text-body">Admin Portal</h1>
                        <p class="text-body-secondary text-sm">Secure authentication required</p>
                    </div>
                </div>

                <!-- Desktop Title -->
                <div class="hidden lg:block mb-8 animate-fade-in-up">
                    <h1 class="text-2xl font-bold tracking-tight text-body">Welcome back</h1>
                    <p class="text-body-secondary text-sm mt-1">Sign in to access the admin dashboard</p>
                </div>

                <?php if ($error): ?>
                    <div class="flex items-center gap-3 p-3.5 bg-red-50 dark:bg-red-500/10 rounded-xl ring-1 ring-red-200 dark:ring-red-500/20 text-red-600 dark:text-red-400 text-sm font-medium animate-slide-down mb-6">
                        <i class="fas fa-circle-exclamation text-red-500 dark:text-red-400"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="space-y-5">
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium text-body-secondary">
                            <i class="fa-regular fa-envelope mr-1.5 text-sky-500"></i>Email / Username
                        </label>
                        <input type="text" name="username" placeholder="admin@company.com"
                            class="w-full px-4 py-3 rounded-xl bg-slate-50 dark:bg-white/[0.05] border border-slate-200 dark:border-white/10 text-body placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/30 focus:border-sky-500 transition-all duration-200">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium text-body-secondary">
                            <i class="fa-solid fa-lock mr-1.5 text-sky-500"></i>Password
                        </label>
                        <div class="relative">
                            <input type="password" name="password" id="admin-login-password" placeholder="Enter password"
                                class="w-full px-4 py-3 pr-11 rounded-xl bg-slate-50 dark:bg-white/[0.05] border border-slate-200 dark:border-white/10 text-body placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/30 focus:border-sky-500 transition-all duration-200">
                            <span class="pw-eye-toggle absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-sky-500 cursor-pointer select-none z-10" role="button" tabindex="0" data-target="admin-login-password">
                                <i class="fa-solid fa-eye text-base"></i>
                            </span>
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full bg-gradient-to-r from-sky-500 to-blue-500 hover:from-sky-400 hover:to-blue-400 text-white font-semibold px-4 py-3.5 rounded-xl shadow-lg shadow-sky-500/25 hover:shadow-xl hover:shadow-sky-500/30 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 relative overflow-hidden">
                        <span class="relative z-10"><i class="fa-solid fa-right-to-bracket mr-2"></i> Sign In</span>
                    </button>
                </form>

                <div class="flex items-center justify-center gap-3 mt-8">
                    <span class="h-px w-12 bg-slate-200 dark:bg-white/10"></span>
                    <a href="../employee/login.php" class="text-sm text-body-muted hover:text-sky-500 font-medium transition-colors flex items-center gap-1.5">
                        <i class="fa-solid fa-arrow-left text-xs"></i> Employee Login
                    </a>
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
