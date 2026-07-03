<?php
$admin_name  = $admin_name  ?? 'Admin';
$page_title  = $page_title  ?? 'HRMS';
$unread_count = 0;
if (isset($conn) && $conn) {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE employee_id IS NULL AND is_read = 0");
    $unread_count = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
}
?>
<header class="glass-strong border-b border-black/[0.06] dark:border-white/[0.06] h-16 flex items-center justify-between px-8 sticky top-0 z-20">
    <div>
        <h1 class="text-lg font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($page_title); ?></h1>
    </div>
    <div class="flex items-center space-x-4">
        <button onclick="toggleTheme()" class="theme-toggle-btn">
            <i class="fa-solid fa-sun icon-sun text-base"></i>
            <i class="fa-solid fa-moon icon-moon text-base"></i>
        </button>
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
                 class="absolute right-0 mt-2 w-72 glass-strong rounded-xl shadow-xl border border-black/10 dark:border-white/10 z-50" style="display: none;">
                <div class="p-3 border-b border-black/[0.06] dark:border-white/[0.06] flex items-center justify-between">
                    <h4 class="text-sm font-bold text-slate-900 dark:text-white"><i class="fa-regular fa-bell mr-1.5 text-violet-400"></i>Notifications</h4>
                    <?php if ($unread_count > 0): ?>
                    <span class="badge badge-rose text-[9px]"><?php echo $unread_count; ?> new</span>
                    <?php endif; ?>
                </div>
                <div class="max-h-48 overflow-y-auto p-4 text-xs text-slate-500 dark:text-zinc-500 text-center">
                    <div class="flex flex-col items-center gap-2 py-4">
                        <i class="fa-regular fa-bell-slash text-2xl text-slate-300 dark:text-zinc-600"></i>
                        <p>No new notifications</p>
                    </div>
                </div>
                <div class="p-2 border-t border-black/[0.06] dark:border-white/[0.06] text-center">
                    <a href="dashboard.php" class="text-xs font-semibold text-violet-600 dark:text-violet-400 hover:text-violet-700 dark:hover:text-violet-300 transition-colors"><i class="fa-regular fa-eye mr-1"></i>View Dashboard</a>
                </div>
            </div>
        </div>
                <div class="flex items-center gap-2 glass rounded-full px-3 py-1.5 hover:bg-white/80 dark:hover:bg-white/[0.08] transition-all duration-200">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-violet-500/20">
                        <?php echo strtoupper(substr($admin_name, 0, 2)); ?>
                    </div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-white hidden sm:block"><?php echo htmlspecialchars($admin_name); ?></div>
                    <a href="<?php echo (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) ? 'logout.php' : '../admin/logout.php'; ?>" class="ml-1 p-1.5 text-slate-400 dark:text-zinc-400 hover:text-rose-500 dark:hover:text-rose-400 hover:bg-slate-100 dark:hover:bg-white/[0.06] rounded-lg transition-all duration-200" title="Logout">
                        <i class="fa-solid fa-right-from-bracket text-xs"></i>
                    </a>
                </div>
    </div>
</header>

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
