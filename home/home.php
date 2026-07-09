<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Modern Payroll & HR Solutions</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></noscript>
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
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { margin: 0; line-height: 1.6; background: var(--bg-primary); color: var(--text-primary); }

        .landing-header {
            position: sticky; top: 0; z-index: 100;
            background: rgba(255,255,255,0.8); backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-subtle);
        }
        .dark .landing-header { background: rgba(9,9,11,0.8); border-color: rgba(255,255,255,0.06); }

        .nav-wrap {
            max-width: 1200px; margin: 0 auto;
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 24px;
        }

        .nav-logo {
            font-size: 22px; font-weight: 800; letter-spacing: -0.5px;
            background: var(--gradient-primary);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-links { display: flex; align-items: center; gap: 8px; }
        .nav-links a {
            text-decoration: none; font-size: 14px; font-weight: 500;
            color: var(--text-secondary); padding: 8px 16px; border-radius: 8px;
            transition: all 0.2s;
        }
        .nav-links a:hover { color: var(--text-primary); background: var(--bg-card-hover); }

        .nav-cta {
            background: linear-gradient(135deg, #1E3A8A, #4F46E5) !important;
            color: #fff !important; padding: 8px 20px !important; border-radius: 10px !important;
            font-weight: 600 !important;
        }
        .nav-cta:hover { transform: translateY(-1px); box-shadow: 0 4px 15px rgba(30,58,138,0.3) !important; }

        .nav-toggle-btn {
            background: none; border: 1px solid var(--border-color); border-radius: 10px;
            padding: 7px 10px; cursor: pointer; color: var(--text-secondary);
            font-size: 15px; transition: all 0.2s;
            display: inline-flex; align-items: center;
        }
        .nav-toggle-btn:hover { background: var(--bg-card-hover); color: var(--text-primary); }

        .mobile-btn { display: none; background: none; border: none; font-size: 24px; color: var(--text-primary); cursor: pointer; }

        @media (max-width: 768px) {
            .mobile-btn { display: block; }
            .nav-links { display: none; flex-direction: column; width: 100%; padding: 16px 0; gap: 4px; }
            .nav-links.open { display: flex; }
            .nav-wrap { flex-wrap: wrap; }
            .nav-links a { width: 100%; text-align: center; }
        }

        /* Hero */
        .hero-section {
            position: relative;
            padding: 100px 24px 80px;
            text-align: center;
            overflow: hidden;
            background: linear-gradient(135deg, #0F172A 0%, #1E3A8A 50%, #1E40AF 100%);
        }
        .dark .hero-section {
            background: linear-gradient(135deg, #020617 0%, #0F172A 50%, #1E3A8A 100%);
        }
        .hero-section::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                radial-gradient(circle at 20% 50%, rgba(30,58,138,0.20) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(79,70,229,0.12) 0%, transparent 50%),
                radial-gradient(circle at 50% 100%, rgba(14,165,233,0.08) 0%, transparent 30%);
        }
        .hero-section::after {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(var(--border-subtle) 1px, transparent 1px),
                linear-gradient(90deg, var(--border-subtle) 1px, transparent 1px);
            background-size: 60px 60px;
            opacity: 0.1;
        }

        .hero-content { position: relative; z-index: 1; max-width: 800px; margin: 0 auto; }

        .hero-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,0.1); backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.15);
            padding: 6px 16px; border-radius: 9999px;
            color: #c4b5fd; font-size: 13px; font-weight: 500;
            margin-bottom: 32px;
        }

        .hero-section h1 {
            font-size: clamp(36px, 5vw, 60px);
            font-weight: 800; line-height: 1.15; letter-spacing: -1px;
            color: #fff; margin-bottom: 20px;
        }
        .hero-section h1 span {
            background: linear-gradient(135deg, #93C5FD, #A5B4FC, #67E8F9);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero-section p {
            font-size: 18px; color: rgba(255,255,255,0.7);
            max-width: 600px; margin: 0 auto 40px;
        }

        .hero-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .hero-actions a {
            text-decoration: none; font-weight: 600; font-size: 15px;
            padding: 14px 28px; border-radius: 12px; transition: all 0.25s;
        }
        .hero-primary {
            background: #fff; color: #4c1d95;
        }
        .hero-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(30,58,138,0.3); }
        .hero-secondary {
            border: 2px solid rgba(255,255,255,0.2); color: #fff;
        }
        .hero-secondary:hover { background: rgba(255,255,255,0.1); transform: translateY(-2px); }

        .hero-stats {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;
            max-width: 900px; margin: 60px auto 0;
            background: rgba(255,255,255,0.05); backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px; padding: 30px;
        }
        .hero-stat { text-align: center; }
        .hero-stat-value {
            font-size: 32px; font-weight: 800; color: #fff; display: block;
        }
        .hero-stat-label {
            font-size: 13px; color: rgba(255,255,255,0.6); font-weight: 500; margin-top: 4px;
        }

        @media (max-width: 768px) {
            .hero-section { padding: 80px 20px 60px; }
            .hero-stats { grid-template-columns: 1fr; gap: 16px; }
        }

        /* Features */
        .features-section {
            max-width: 1200px; margin: 0 auto; padding: 100px 24px;
        }
        .section-label {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 12px; border-radius: 9999px;
            font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
            background: var(--badge-bg); color: var(--badge-text); border: 1px solid var(--badge-border);
        }
        .section-heading {
            font-size: clamp(28px, 3vw, 40px);
            font-weight: 800; letter-spacing: -0.5px;
            margin: 12px 0 16px;
        }
        .section-sub {
            font-size: 16px; color: var(--text-secondary); max-width: 600px;
        }

        .features-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px; margin-top: 48px;
        }
        .feature-card {
            background: var(--glass-strong-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-strong-border);
            border-radius: 20px; padding: 28px;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
            border-color: rgba(30,58,138,0.2);
        }
        .feature-icon-wrap {
            width: 48px; height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(30,58,138,0.1), rgba(79,70,229,0.1));
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; margin-bottom: 16px;
        }
        .feature-card h4 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .feature-card p { font-size: 14px; color: var(--text-secondary); line-height: 1.7; }

        /* CTA */
        .cta-section {
            max-width: 800px; margin: 0 auto 80px; padding: 0 24px;
            text-align: center;
            background: linear-gradient(135deg, rgba(30,58,138,0.05), rgba(79,70,229,0.05));
            border: 1px solid var(--glass-strong-border);
            border-radius: 24px; padding: 60px 40px;
        }
        .cta-section h2 { font-size: 32px; font-weight: 800; margin-bottom: 12px; }
        .cta-section p { color: var(--text-secondary); margin-bottom: 32px; max-width: 500px; margin-left: auto; margin-right: auto; }
        .cta-buttons { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .cta-primary {
            text-decoration: none; font-weight: 600; font-size: 15px;
            padding: 14px 28px; border-radius: 12px;
            background: linear-gradient(135deg, #1E3A8A, #4F46E5);
            color: #fff; transition: all 0.25s;
        }
        .cta-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(30,58,138,0.3); }
        .cta-secondary {
            text-decoration: none; font-weight: 600; font-size: 15px;
            padding: 14px 28px; border-radius: 12px;
            border: 1px solid var(--border-color); color: var(--text-primary);
            transition: all 0.25s;
        }
        .cta-secondary:hover { background: var(--bg-card-hover); }

        /* Footer */
        .landing-footer {
            border-top: 1px solid var(--border-subtle);
            padding: 40px 24px; text-align: center;
        }
        .landing-footer p { font-size: 13px; color: var(--text-muted); }
        .landing-footer-links { display: flex; justify-content: center; gap: 24px; margin-bottom: 16px; }
        .landing-footer-links a { text-decoration: none; font-size: 13px; color: var(--text-secondary); transition: color 0.2s; }
        .landing-footer-links a:hover { color: var(--text-primary); }

        .dark .hero-section::after { opacity: 0.05; }
    </style>
</head>

<body>

    <header class="landing-header">
        <div class="nav-wrap">
            <div class="nav-logo">HNIN AKARI NWE</div>
            <button class="mobile-btn" onclick="document.getElementById('nav-links').classList.toggle('open')">☰</button>
            <div class="nav-links" id="nav-links">
                <a href="#features">Features</a>
                <a href="#cta">Why HNIN AKARI NWE</a>
                <a href="../employee/login.php" class="nav-cta"><i class="fa-regular fa-user mr-1"></i>Employee Portal</a>
                <a href="../admin/login.php"><i class="fa-solid fa-shield-halved mr-1"></i>Admin</a>
                <button onclick="toggleTheme()" class="nav-toggle-btn">
                    <i class="fa-solid fa-sun icon-sun"></i>
                    <i class="fa-solid fa-moon icon-moon"></i>
                </button>
            </div>
        </div>
    </header>

    <section class="hero-section">
        <div class="hero-content">
            <div class="hero-badge animate-fade-in-up">
                <span class="notif-dot live"></span> Now serving 300+ enterprises
            </div>
            <h1 class="animate-fade-in-up stagger-1">
                Intelligent HR &<br>
                <span>Payroll Automation</span>
            </h1>
            <p class="animate-fade-in-up stagger-2">
                The all-in-one platform that streamlines attendance, payroll, leave management, and workforce analytics — designed for precision at scale.
            </p>
            <div class="hero-actions animate-fade-in-up stagger-3">
                <a href="../employee/login.php" class="hero-primary"><i class="fa-regular fa-user mr-2"></i>Employee Login</a>
                <a href="../admin/login.php" class="hero-secondary"><i class="fa-solid fa-shield-halved mr-2"></i>Admin Portal</a>
            </div>
            <div class="hero-stats animate-fade-in-up stagger-4">
                <div class="hero-stat">
                    <span class="hero-stat-value">99.9%</span>
                    <span class="hero-stat-label">Uptime</span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-value">312+</span>
                    <span class="hero-stat-label">Active Employees</span>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-value">$2.4M</span>
                    <span class="hero-stat-label">Monthly Payroll</span>
                </div>
            </div>
        </div>
    </section>

    <section class="features-section" id="features">
        <div style="text-align: center; margin-bottom: 48px;">
            <div class="section-label"><i class="fa-solid fa-cog mr-1"></i>Platform Capabilities</div>
            <h2 class="section-heading">Everything you need to<br>run your workforce</h2>
            <p class="section-sub" style="margin: 0 auto;">From time tracking to tax-ready payroll — HNIN AKARI NWE brings enterprise-grade tools to your fingertips.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon-wrap" style="color: #1E3A8A;"><i class="fa-solid fa-fingerprint"></i></div>
                <h4>Attendance Tracking</h4>
                <p>Real-time check-in/out with automatic late detection, monthly summaries, and per-employee attendance history.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon-wrap" style="color: #F59E0B;"><i class="fa-solid fa-plane-departure"></i></div>
                <h4>Leave Management</h4>
                <p>End-to-end leave request workflow with approval chains, balance tracking, and department-level reporting.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon-wrap" style="color: #10B981;"><i class="fa-solid fa-calculator"></i></div>
                <h4>Payroll Engine</h4>
                <p>Automated salary calculation with OT, bonuses, deductions, and tax — generates detailed payslips in seconds.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon-wrap" style="color: #4F46E5;"><i class="fa-solid fa-clock"></i></div>
                <h4>Overtime Tracking</h4>
                <p>Request and approve overtime with hour logging, rate calculation, and automatic payroll integration.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon-wrap" style="color: #3B82F6;"><i class="fa-solid fa-chart-line"></i></div>
                <h4>Analytics & Reports</h4>
                <p>Monthly trends, department distribution, annual payroll summaries, and exportable performance metrics.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon-wrap" style="color: #F43F5E;"><i class="fa-solid fa-shield-halved"></i></div>
                <h4>Secure & Compliant</h4>
                <p>Enterprise-grade security with encrypted passwords, session management, and role-based access control.</p>
            </div>
        </div>
    </section>

    <section class="cta-section" id="cta">
        <h2>Ready to get started?</h2>
        <p>Sign in to your employee dashboard or access the admin portal to manage your organization.</p>
        <div class="cta-buttons">
            <a href="../employee/login.php" class="cta-primary"><i class="fa-regular fa-user mr-2"></i>Employee Login</a>
            <a href="../admin/login.php" class="cta-secondary"><i class="fa-solid fa-shield-halved mr-2"></i>Admin Portal</a>
        </div>
    </section>

    <footer class="landing-footer">
        <div class="landing-footer-links">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact Support</a>
        </div>
        <p>&copy; 2026 HNIN AKARI NWE Management Systems. All rights reserved. Built with precision for scale.</p>
    </footer>

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