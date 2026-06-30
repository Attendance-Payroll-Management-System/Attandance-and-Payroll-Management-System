<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Directory</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-purple-400 font-sans flex min-h-screen">




    <aside class="w-72 min-h-screen bg-blue-900 text-white">

        <!-- Logo -->
        <div class="bg-white text-center py-5">
            <h1 class="text-2xl font-bold text-black">
                HR PAYROLL
            </h1>
            <p class="text-orange-500">
                Management System
            </p>
        </div>

        <!-- User -->
        <div class="p-5 border-b border-blue-700">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-full bg-gray-300"></div>

                <div>
                    <p class="text-gray-300 text-sm">
                        Welcome
                    </p>

                    <h4 class="font-semibold">
                        Admin
                    </h4>
                </div>
            </div>
        </div>

        <!-- Menu -->
        <nav class="mt-2">

            <a href="index.php"
                class="flex items-center gap-3 px-6 py-4 hover:bg-blue-800">

                <i class="fas fa-gauge-high"></i>
                Dashboard

            </a>

            <details>
                <summary class="flex justify-between px-6 py-4 cursor-pointer hover:bg-blue-800">

                    <span>
                        <i class="fas fa-users mr-3"></i>
                        Employee
                    </span>

                    <i class="fas fa-chevron-down"></i>

                </summary>

                <div class="bg-blue-800">

                    <a href="employees.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Employee List
                    </a>

                    <a href="../admin/insert1.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Add Employee
                    </a>
                    <a href="insert1.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Edit Employee
                    </a>

                </div>
            </details>

            <details>
                <summary class="flex justify-between px-6 py-4 cursor-pointer hover:bg-yellow-800">

                    <span>
                        <i class="fas fa-building mr-3"></i>
                        Department
                    </span>

                    <i class="fas fa-chevron-down"></i>

                </summary>

                <div class="bg-blue-800">

                    <a href="departments.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Department List
                    </a>

                </div>
            </details>

            <details open>
                <summary class="hover:bg-yellow-500 text-black px-6 py-4 font-semibold">

                    <i class="fas fa-calendar-check mr-3"></i>
                    Attendance

                </summary>

                <div class="bg-blue-800">

                    <a href="attendance.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Daily Attendance
                    </a>

                    <a href="attendance_report.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Attendance Report
                    </a>

                </div>
            </details>

            <details>
                <summary class="flex justify-between px-6 py-4 cursor-pointer hover:bg-blue-800">

                    <span>
                        <i class="fas fa-bed mr-3"></i>
                        Leave
                    </span>

                    <i class="fas fa-chevron-down"></i>

                </summary>

                <div class="bg-blue-800">

                    <a href="leave_request.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Leave Request
                    </a>

                    <a href="leave_report.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Leave Report
                    </a>

                </div>
            </details>

            <details>
                <summary class="flex justify-between px-6 py-4 cursor-pointer hover:bg-blue-800">

                    <span>
                        <i class="fas fa-clock mr-3"></i>
                        Overtime
                    </span>

                    <i class="fas fa-chevron-down"></i>

                </summary>

                <div class="bg-blue-800">
                    <a href="overtime.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Overtime Entry
                    </a>

                    <a href="overtime_report.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Overtime Report
                    </a>

                </div>
            </details>

            <details>
                <summary class="flex justify-between px-6 py-4 cursor-pointer hover:bg-blue-800">

                    <span>
                        <i class="fas fa-money-bill-wave mr-3"></i>
                        Payroll
                    </span>

                    <i class="fas fa-chevron-down"></i>

                </summary>

                <div class="bg-blue-800">

                    <a href="generate_payroll.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Generate Payroll
                    </a>

                    <a href="salary_report.php"
                        class="block px-12 py-3 hover:bg-blue-700">
                        Salary Report
                    </a>

                </div>
            </details>

            <a href="holiday.php"
                class="flex items-center gap-3 px-6 py-4 hover:bg-blue-800">

                <i class="fas fa-plane"></i>
                Holiday

            </a>

            <a href="settings.php"
                class="flex items-center gap-3 px-6 py-4 hover:bg-blue-800">

                <i class="fas fa-gear"></i>
                Settings

            </a>

        </nav>

    </aside>
</body>

</html>