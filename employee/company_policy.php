<?php
session_start();
require_once "../config/db.php";
require_once "../config/helpers.php";
if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit; }
$employee_id = $_SESSION['employee_id'];
set_mmt_timezone();

// Fetch policies
$policies = $conn->query("SELECT p.*, e.name as author_name FROM policies p LEFT JOIN employee e ON p.created_by = e.id ORDER BY p.category, p.title")->fetch_all(MYSQLI_ASSOC);

// Group by category
$grouped = [];
foreach ($policies as $p) {
    $grouped[$p['category']][] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Company Policies</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased">
    <?php include "../includes/sidebar.php"; ?>
    <div class="main-wrapper min-h-screen flex flex-col">
        <?php $page_title = "Company Policies"; $page_subtitle = "Review company policies and guidelines"; include "../includes/topbar.php"; ?>
        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full">

            <?php if (empty($policies)): ?>
            <div class="glass-strong rounded-2xl p-8 text-center">
                <i class="fa-regular fa-file-lines text-4xl text-zinc-600 block mb-3"></i>
                <p class="text-zinc-500">No company policies available at this time.</p>
                <p class="text-xs text-zinc-600 mt-1">Please check back later or contact HR.</p>
            </div>
            <?php else: ?>

            <!-- Quick Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="glass-strong rounded-2xl p-4 text-center">
                    <div class="text-2xl font-bold text-gradient"><?php echo count($policies); ?></div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Total Policies</span>
                </div>
                <div class="glass-strong rounded-2xl p-4 text-center">
                    <div class="text-2xl font-bold text-gradient-violet"><?php echo count($grouped); ?></div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Categories</span>
                </div>
                <?php
                $category_icons = [
                    'Attendance' => ['icon' => 'calendar-check', 'color' => 'emerald'],
                    'Leave' => ['icon' => 'plane-departure', 'color' => 'blue'],
                    'Overtime' => ['icon' => 'clock', 'color' => 'amber'],
                    'Salary' => ['icon' => 'money-bill-wave', 'color' => 'green'],
                    'General' => ['icon' => 'info-circle', 'color' => 'violet'],
                    'Conduct' => ['icon' => 'shield-halved', 'color' => 'rose'],
                    'Benefits' => ['icon' => 'gift', 'color' => 'cyan'],
                ];
                foreach (array_slice($grouped, 0, 2) as $cat => $items):
                    $icon_data = $category_icons[$cat] ?? ['icon' => 'folder', 'color' => 'slate'];
                ?>
                <div class="glass-strong rounded-2xl p-4 text-center">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-<?php echo $icon_data['color']; ?>-500/20 to-<?php echo $icon_data['color']; ?>-500/10 text-<?php echo $icon_data['color']; ?>-400 flex items-center justify-center text-lg mx-auto mb-2">
                        <i class="fa-solid fa-<?php echo $icon_data['icon']; ?>"></i>
                    </div>
                    <div class="text-lg font-bold text-white"><?php echo $cat; ?></div>
                    <span class="text-xs text-zinc-500"><?php echo count($items); ?> policies</span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Policies by Category -->
            <?php foreach ($grouped as $cat => $items):
                $icon_data = $category_icons[$cat] ?? ['icon' => 'folder', 'color' => 'slate'];
            ?>
            <div class="glass-strong rounded-2xl overflow-hidden animate-fade-in-up">
                <div class="px-6 py-4 border-b border-white/[0.06] bg-gradient-to-r from-<?php echo $icon_data['color']; ?>-500/10 to-<?php echo $icon_data['color']; ?>-500/5">
                    <h4 class="font-bold text-white flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-<?php echo $icon_data['color']; ?>-500/20 text-<?php echo $icon_data['color']; ?>-400 flex items-center justify-center">
                            <i class="fa-solid fa-<?php echo $icon_data['icon']; ?> text-sm"></i>
                        </div>
                        <?php echo htmlspecialchars($cat); ?>
                        <span class="badge badge-<?php echo $icon_data['color']; ?> text-[10px]"><?php echo count($items); ?> policies</span>
                    </h4>
                </div>
                <div class="divide-y divide-white/[0.04]">
                    <?php foreach ($items as $policy): ?>
                    <div class="px-6 py-4 hover:bg-white/[0.02] transition-colors" x-data="{ expanded: false }">
                        <div class="flex items-center justify-between cursor-pointer" @click="expanded = !expanded">
                            <div class="flex items-center gap-3">
                                <i class="fa-solid fa-file-alt text-<?php echo $icon_data['color']; ?>-400"></i>
                                <span class="font-medium text-white text-sm"><?php echo htmlspecialchars($policy['title']); ?></span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-[10px] text-zinc-500"><?php echo date('M d, Y', strtotime($policy['created_at'])); ?></span>
                                <i class="fa-solid fa-chevron-down text-xs text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': expanded }"></i>
                            </div>
                        </div>
                        <div x-show="expanded" x-transition class="mt-4 ml-8 p-4 bg-white/[0.03] rounded-xl border border-white/[0.06]">
                            <p class="text-sm text-zinc-300 whitespace-pre-wrap leading-relaxed"><?php echo nl2br(htmlspecialchars($policy['content'])); ?></p>
                            <div class="mt-3 pt-3 border-t border-white/[0.06] flex items-center gap-2 text-[10px] text-zinc-500">
                                <i class="fa-regular fa-clock"></i>
                                Last updated: <?php echo date('M d, Y h:i A', strtotime($policy['updated_at'])); ?>
                                <?php if ($policy['author_name']): ?>
                                <span class="mx-1">|</span>
                                <i class="fa-regular fa-user"></i>
                                <?php echo htmlspecialchars($policy['author_name']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>

        </main>
        <?php include "../includes/footer.php"; ?>
    </div>
</body>
</html>
