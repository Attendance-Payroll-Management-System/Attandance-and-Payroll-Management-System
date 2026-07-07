<?php
$admin_name  = $admin_name  ?? ($_SESSION['admin_name'] ?? 'Admin');
$admin_email = $_SESSION['admin_email'] ?? 'admin@aura.hr';
$admin_photo = '';
$page_title  = $page_title  ?? 'HRMS';
$page_subtitle = $page_subtitle ?? '';
$page_actions  = $page_actions  ?? '';
$unread_count = 0;
$topbar_notifications = [];

$is_admin = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false);
$topbar_emp_id = $is_admin ? null : ($_SESSION['employee_id'] ?? null);

if (isset($conn) && $conn) {
    require_once __DIR__ . '/../config/notifications.php';
    if ($topbar_emp_id) {
        $unread_count = get_unread_count($conn, $topbar_emp_id);
        $topbar_notifications = get_notifications($conn, $topbar_emp_id, 5);
    } else {
        $unread_count = get_unread_count($conn);
        $topbar_notifications = get_notifications($conn, null, 5);
    }
    // Get admin profile photo
    $admin_id = $_SESSION['admin_id'] ?? null;
    if ($admin_id) {
        $res = $conn->query("SHOW COLUMNS FROM employee LIKE 'profile_photo'");
        if ($res && $res->num_rows > 0) {
            $res = $conn->query("SELECT profile_photo, email FROM employee WHERE id = " . (int)$admin_id);
            if ($res && $row = $res->fetch_assoc()) {
                $admin_photo = $row['profile_photo'] ?? '';
                if (!empty($row['email'])) $admin_email = $row['email'];
            }
        } else {
            $res = $conn->query("SELECT email FROM employee WHERE id = " . (int)$admin_id);
            if ($res && $row = $res->fetch_assoc()) {
                if (!empty($row['email'])) $admin_email = $row['email'];
            }
        }
    }
}

// Get short name (first name only)
$short_name = explode(' ', $admin_name)[0];
?>
<header class="sticky top-0 z-20 bg-white/80 dark:bg-[#0a0a0f]/80 backdrop-blur-xl border-b border-slate-200 dark:border-white/[0.06]">
    <div class="flex items-center justify-between px-8 min-h-[68px]">
        <div class="min-w-0 py-2">
            <div class="flex items-center gap-3">
                <div class="w-1 h-7 rounded-full bg-gradient-to-b from-violet-500 to-fuchsia-500 shrink-0"></div>
                <h1 class="text-lg font-bold tracking-tight text-slate-900 dark:text-white"><?php echo htmlspecialchars($page_title); ?></h1>
            </div>
            <?php if ($page_subtitle): ?>
            <p class="text-xs text-slate-500 dark:text-zinc-400 mt-0.5 ml-4"><?php echo htmlspecialchars($page_subtitle); ?></p>
            <?php endif; ?>
        </div>
        <div class="flex items-center space-x-3">
            <?php if ($page_actions): ?>
            <?php echo $page_actions; ?>
            <?php endif; ?>
            <button onclick="toggleTheme()" class="theme-toggle-btn">
                <i class="fa-solid fa-sun icon-sun text-base"></i>
                <i class="fa-solid fa-moon icon-moon text-base"></i>
            </button>

            <!-- Notifications -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="relative p-2 text-slate-500 dark:text-zinc-400 hover:text-violet-600 dark:hover:text-white bg-slate-100 dark:bg-white/[0.06] rounded-full transition-all duration-200 hover:scale-105 active:scale-95">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($unread_count > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-rose-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center shadow-lg shadow-rose-500/30 animate-scale-in"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
                <div x-show="open" @click.outside="open = false"
                     x-transition:enter="transition-all duration-300 ease-out"
                     x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition-all duration-200 ease-in"
                     x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                     x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
                     class="absolute right-0 mt-2 w-96 glass-strong rounded-xl shadow-xl border border-black/10 dark:border-white/10 z-50" style="display: none;">
                    <div class="p-3 border-b border-black/[0.06] dark:border-white/[0.06] flex items-center justify-between">
                        <h4 class="text-sm font-bold text-slate-900 dark:text-white"><i class="fa-regular fa-bell mr-1.5 text-violet-400"></i>Notifications</h4>
                        <?php if ($unread_count > 0): ?>
                        <a href="mark_notifications_read.php" class="text-[10px] font-semibold text-violet-600 dark:text-violet-400 hover:text-violet-700 dark:hover:text-violet-300 transition-colors">Mark all read</a>
                        <?php endif; ?>
                    </div>
                    <div class="max-h-96 overflow-y-auto">
                        <?php if (empty($topbar_notifications)): ?>
                        <div class="p-4 text-xs text-slate-500 dark:text-zinc-500 text-center">
                            <i class="fa-regular fa-bell-slash text-2xl text-slate-300 dark:text-zinc-600 block mb-2"></i>
                            <p>No notifications</p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($topbar_notifications as $noti): ?>
                            <a href="<?php echo $noti['link'] ?: '#'; ?>" class="block px-4 py-3 border-b border-black/[0.04] dark:border-white/[0.04] hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition <?php echo !$noti['is_read'] ? 'bg-violet-500/5 dark:bg-violet-500/10' : ''; ?>">
                                <p class="text-xs text-slate-700 dark:text-zinc-300"><?php echo htmlspecialchars($noti['message']); ?></p>
                                <p class="text-[10px] text-slate-400 dark:text-zinc-500 mt-1"><?php echo date('M d, h:i A', strtotime($noti['created_at'])); ?></p>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 border-t border-black/[0.06] dark:border-white/[0.06] text-center">
                        <a href="dashboard.php" class="text-xs font-semibold text-violet-600 dark:text-violet-400 hover:text-violet-700 dark:hover:text-violet-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>View Dashboard</a>
                    </div>
                </div>
            </div>

            <!-- Admin Profile Dropdown -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="flex items-center gap-2 hover:bg-slate-100 dark:hover:bg-white/[0.06] rounded-full pl-1 pr-3 py-1 transition-all duration-200">
                    <?php if (!empty($admin_photo)): ?>
                    <img src="../<?php echo htmlspecialchars($admin_photo); ?>" alt="Profile" class="w-8 h-8 rounded-full object-cover border-2 border-violet-500/30">
                    <?php else: ?>
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-violet-500/20">
                        <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
                    </div>
                    <?php endif; ?>
                    <span class="text-sm font-semibold text-slate-700 dark:text-zinc-300 hidden sm:block"><?php echo htmlspecialchars($short_name); ?></span>
                    <i class="fa-solid fa-chevron-down text-[10px] text-slate-400 dark:text-zinc-500 hidden sm:block transition-transform duration-200" :class="{ 'rotate-180': open }"></i>
                </button>

                <!-- Dropdown Menu -->
                <div x-show="open" @click.outside="open = false"
                    x-transition:enter="transition-all duration-200 ease-out"
                    x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="transition-all duration-150 ease-in"
                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                    x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
                    class="absolute right-0 mt-2 w-72 glass-strong rounded-2xl shadow-2xl border border-black/10 dark:border-white/10 z-50 overflow-hidden" style="display: none;">

                    <!-- Admin Info Header -->
                    <div class="p-4 bg-gradient-to-r from-violet-500/10 to-fuchsia-500/10 border-b border-black/[0.06] dark:border-white/[0.06]">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($admin_photo)): ?>
                            <img src="../<?php echo htmlspecialchars($admin_photo); ?>" alt="Profile" class="w-12 h-12 rounded-full object-cover border-2 border-white dark:border-[#18181b] shadow-lg">
                            <?php else: ?>
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-lg font-bold shadow-lg shadow-violet-500/20">
                                <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($admin_name); ?></p>
                                <p class="text-[11px] text-slate-500 dark:text-zinc-400 truncate"><?php echo htmlspecialchars($admin_email); ?></p>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-violet-500/20 text-violet-600 dark:text-violet-400">Administrator</span>
                            </div>
                        </div>
                    </div>

                    <!-- Menu Items -->
                    <div class="p-2">
                        <a href="profile.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-colors">
                            <i class="fa-solid fa-user text-xs text-slate-400 dark:text-zinc-500 w-5 text-center"></i> View Profile
                        </a>
                        <a href="profile.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-colors">
                            <i class="fa-solid fa-user-pen text-xs text-slate-400 dark:text-zinc-500 w-5 text-center"></i> Edit Profile
                        </a>
                        <a href="profile.php#settings" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-colors">
                            <i class="fa-solid fa-gear text-xs text-slate-400 dark:text-zinc-500 w-5 text-center"></i> Account Settings
                        </a>
                        <a href="profile.php#password" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-colors">
                            <i class="fa-solid fa-lock text-xs text-slate-400 dark:text-zinc-500 w-5 text-center"></i> Change Password
                        </a>
                        <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-colors">
                            <i class="fa-solid fa-bell text-xs text-slate-400 dark:text-zinc-500 w-5 text-center"></i> Notification Preferences
                        </a>
                        <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-slate-700 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-white/[0.06] transition-colors">
                            <i class="fa-solid fa-circle-question text-xs text-slate-400 dark:text-zinc-500 w-5 text-center"></i> Help & Support
                        </a>
                    </div>

                    <!-- Logout -->
                    <div class="p-2 border-t border-black/[0.06] dark:border-white/[0.06]">
                        <a href="<?php echo (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) ? 'logout.php' : '../admin/logout.php'; ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-colors">
                            <i class="fa-solid fa-right-from-bracket text-xs w-5 text-center"></i> Logout
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</header>
