<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HRMS - Employee Portal</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cloudflare.com">
</head>

<body class="bg-slate-100 font-sans antialiased flex h-screen overflow-hidden">

    <!-- SIDEBAR NAV -->
    <aside class="w-64 bg-blue-900 text-white flex flex-col justify-between p-4 shrink-0">
        <div>
            <!-- Logo Section -->
            <div class="flex items-center gap-3 px-2 py-4 border-b border-blue-800 mb-6">
                <div class="bg-blue-600 p-2 rounded-lg text-xl"><i class="fa-solid fa-users"></i></div>
                <div>
                    <h1 class="font-bold text-lg leading-none">HRMS</h1>
                    <span class="text-xs text-blue-300">Employee Portal</span>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-1">
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg bg-blue-700 text-white font-medium transition">
                    <i class="fa-solid fa-house w-5 text-center"></i> Dashboard
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-calendar-check w-5 text-center"></i> Attendance
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-envelope-open-text w-5 text-center"></i> Leave Request
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-clock-rotate-left w-5 text-center"></i> Overtime Request
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-folder-open w-5 text-center"></i> My Records
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-file-invoice-dollar w-5 text-center"></i> Payslip
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-user w-5 text-center"></i> Profile
                </a>
                <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg text-blue-200 hover:bg-blue-800 hover:text-white transition">
                    <i class="fa-solid fa-gear w-5 text-center"></i> Settings
                </a>
            </nav>
        </div>

        <!-- Logout Button -->
        <a href="#" class="flex items-center gap-3 px-4 py-3 rounded-lg text-red-300 hover:bg-red-900/50 hover:text-red-200 transition">
            <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Logout
        </a>
    </aside>

    <!-- MAIN BODY SECTION -->
    <div class="flex-1 flex flex-col h-full overflow-y-auto">

        <!-- TOP HEADER -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-4">
                <button class="text-slate-600 text-xl md:hidden"><i class="fa-solid fa-bars"></i></button>
                <div>
                    <h2 class="text-xl font-bold text-slate-800">Good Morning, Mg Mg <span class="text-amber-500">👋</span></h2>
                    <p class="text-xs text-slate-500">Welcome back! Here's what's happening today.</p>
                </div>
            </div>

            <!-- User Notification & Profile Info -->
            <div class="flex items-center gap-4">
                <button class="relative p-2 text-slate-500 hover:text-slate-700 bg-slate-100 rounded-full">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <span class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                </button>
                <div class="flex items-center gap-3 border-l border-slate-200 pl-4">
                    <img src="https://unsplash.com" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                    <div class="hidden sm:block">
                        <h4 class="text-sm font-semibold text-slate-800">Mg Mg</h4>
                        <span class="text-xs text-slate-400 block -mt-1">Employee</span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400"></i>
                </div>
            </div>
        </header>

        <!-- DASHBOARD WORKSPACE -->
        <main class="p-6 space-y-6">

            <!-- PHP Mockup Data Integration -->
            <?php
            // Simulating database fetch variables for display counters
            $presentDays = 24;
            $leaveDays = 2;
            $overtimeHours = 12.5;
            $upcomingLeaveCount = 1;
            $upcomingLeaveDate = "15 Jun 2026";
            $currentDate = date('Y-m-d');
            ?>

            <!-- STATISTICAL STATS CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Card 1 -->
                <div class="bg-white p-4 rounded-xl border border-slate-200 flex items-center gap-4">
                    <div class="bg-blue-100 text-blue-600 p-4 rounded-xl text-xl"><i class="fa-solid fa-calendar-days"></i></div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block">Present Days</span>
                        <span class="text-2xl font-bold text-slate-800"><?php echo $presentDays; ?></span>
                        <span class="text-xs text-blue-600 block font-medium">This Month</span>
                    </div>
                </div>
                <!-- Card 2 -->
                <div class="bg-white p-4 rounded-xl border border-slate-200 flex items-center gap-4">
                    <div class="bg-emerald-100 text-emerald-600 p-4 rounded-xl text-xl"><i class="fa-solid fa-plane-departure"></i></div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block">Leave Days</span>
                        <span class="text-2xl font-bold text-slate-800"><?php echo $leaveDays; ?></span>
                        <span class="text-xs text-emerald-600 block font-medium">This Month</span>
                    </div>
                </div>
                <!-- Card 3 -->
                <div class="bg-white p-4 rounded-xl border border-slate-200 flex items-center gap-4">
                    <div class="bg-purple-100 text-purple-600 p-4 rounded-xl text-xl"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block">Overtime Hours</span>
                        <span class="text-2xl font-bold text-slate-800"><?php echo $overtimeHours; ?></span>
                        <span class="text-xs text-purple-600 block font-medium">This Month</span>
                    </div>
                </div>
                <!-- Card 4 -->
                <div class="bg-white p-4 rounded-xl border border-slate-200 flex items-center gap-4">
                    <div class="bg-orange-100 text-orange-600 p-4 rounded-xl text-xl"><i class="fa-solid fa-calendar-minus"></i></div>
                    <div>
                        <span class="text-xs text-slate-400 font-medium uppercase tracking-wider block">Upcoming Leave</span>
                        <span class="text-2xl font-bold text-slate-800"><?php echo $upcomingLeaveCount; ?></span>
                        <span class="text-xs text-slate-500 block font-medium">Next: <span class="text-orange-600 font-semibold"><?php echo $upcomingLeaveDate; ?></span></span>
                    </div>
                </div>
            </div>

            <!-- ACTIONABLE FORMS SECTIONS -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Card Form 1: Daily Attendance -->
                <div class="bg-white p-5 rounded-xl border border-slate-200 flex flex-col justify-between shadow-sm">
                    <div>
                        <div class="flex items-start gap-3 mb-4">
                            <div class="bg-blue-100 text-blue-600 px-3 py-2 rounded-lg"><i class="fa-solid fa-fingerprint"></i></div>
                            <div>
                                <h3 class="font-bold text-slate-800">Daily Attendance</h3>
                                <p class="text-xs text-slate-400">Mark your check in and check out</p>
                            </div>
                        </div>
                        <form action="" method="POST" class="space-y-3 text-slate-700">
                            <div>
                                <label class="text-xs font-semibold text-slate-500 block mb-1">Date</label>
                                <input type="date" value="<?php echo $currentDate; ?>" class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg focus:outline-blue-500">
                            </div>