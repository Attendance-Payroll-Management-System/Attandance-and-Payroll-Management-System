<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aura | Modern Payroll Solutions</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1A237E;
            --secondary: #0D47A1;
            --tertiary: #E3F2FD;
            --neutral-dark: #37474F;
            --neutral-light: #F5F7FA;
            --white: #FFFFFF;
            --accent: #4CAF50;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Manrope', sans-serif;
        }

        body {
            background-color: var(--neutral-light);
            color: var(--neutral-dark);
            line-height: 1.6;
        }

        /* Navigation */
        header {
            background-color: var(--white);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
        }

        .logo {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: 1px;
        }

        nav a {
            text-decoration: none;
            color: var(--neutral-dark);
            margin-left: 30px;
            font-weight: 500;
            transition: color 0.3s;
        }

        nav a:hover {
            color: var(--secondary);
        }

        .btn-demo {
            background-color: var(--primary);
            color: var(--white);
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            transition: background 0.3s;
        }

        .btn-demo:hover {
            background-color: var(--secondary);
            color: var(--white);
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: var(--white);
            padding: 100px 20px;
            text-align: center;
        }

        .hero-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .hero h1 {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .hero p {
            font-size: 18px;
            margin-bottom: 40px;
            opacity: 0.9;
        }

        .hero-buttons .btn-primary {
            background-color: var(--white);
            color: var(--primary);
            padding: 15px 30px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            margin-right: 15px;
            transition: opacity 0.3s;
        }

        .hero-buttons .btn-secondary {
            border: 2px solid var(--white);
            color: var(--white);
            padding: 13px 30px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            transition: background 0.3s;
        }

        .hero-buttons .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        /* Dashboard Preview / Stats */
        .dashboard-preview {
            max-width: 1100px;
            margin: -50px auto 80px;
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: var(--neutral-light);
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid var(--secondary);
        }

        .stat-card h3 {
            font-size: 14px;
            color: #78909C;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
        }

        /* Features Section */
        .features {
            max-width: 1200px;
            margin: 0 auto 80px;
            padding: 0 20px;
        }

        .section-title {
            text-align: center;
            font-size: 32px;
            margin-bottom: 50px;
            color: var(--primary);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .feature-card {
            background: var(--white);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);
            transition: transform 0.3s;
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .feature-icon {
            font-size: 32px;
            margin-bottom: 15px;
            color: var(--secondary);
        }

        .feature-card h4 {
            font-size: 20px;
            margin-bottom: 12px;
            color: var(--primary);
        }

        /* Footer */
        footer {
            background-color: var(--primary);
            color: var(--white);
            padding: 40px 20px;
            text-align: center;
            font-size: 14px;
        }

        footer p {
            opacity: 0.7;
        }
    </style>
</head>

<body>

    <!-- Header Navigation -->
    <header>
        <div class="nav-container">
            <div class="logo">AURA</div>
            <nav>
                <a href="#features">Features</a>
                <a href="#analytics">Analytics</a>
                <a href="#compliance">Compliance</a>
                <a href="#" class="btn-demo">Request Demo</a>
            </nav>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-container">
            <h1>Streamline Your Workforce Management</h1>
            <p>The intelligent payroll and HR engine built for precision-scale enterprises. Secure, automated, and designed for global compliance.</p>
            <div class="hero-buttons">
                <a href="#" class="btn-primary">Book Demo →</a>
                <a href="#" class="btn-secondary">View Features</a>
            </div>
        </div>
    </section>

    <!-- Dashboard Quick Stats Component -->
    <section class="dashboard-preview">
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Monthly Payroll</h3>
                <div class="value">$128,450.00</div>
            </div>
            <div class="stat-card">
                <h3>Active Employees</h3>
                <div class="value">312</div>
            </div>
            <div class="stat-card">
                <h3>Pending Approvals</h3>
                <div class="value" style="color: #D32F2F;">08</div>
            </div>
        </div>
    </section>
    <!-- Features Section -->
    <section class="features" id="features">
        <h2 class="section-title">Engineered for Accuracy</h2>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">⚙️</div>
                <h4>Payroll Automation</h4>
                <p>Eliminate manual entry errors. Our engine automatically calculates taxes, deductions, and local filings across 140+ countries in real-time.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">👤</div>
                <h4>Employee Self-Service</h4>
                <p>Empower your employees with a modern portal to manage benefits, view payslips, and update personal data without HR intervention.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h4>Advanced Reporting</h4>
                <p>Generate complex financial audits and workforce insights in seconds with our highly customizable analytical reporting engine.</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <p>&copy; 2026 Aura Management Systems. All rights reserved. Built for scale, security, and stability.</p>
    </footer>

</body>

</html>