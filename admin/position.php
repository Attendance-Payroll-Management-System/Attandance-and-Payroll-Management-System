<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['position_name'])) {
    $name = trim($_POST['position_name']);
    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO positions (position_name) VALUES (?)");
        $stmt->bind_param('s', $name);
        if ($stmt->execute()) {
            $message = 'Position added successfully.';
            $message_type = 'success';
        } else {
            $message = 'Error adding position.';
            $message_type = 'error';
        }
        $stmt->close();
    } else {
        $message = 'Position name is required.';
        $message_type = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $stmt = $conn->prepare("DELETE FROM positions WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $message = 'Position deleted.';
    $message_type = 'success';
}

$positions = $conn->query("SELECT * FROM positions ORDER BY position_name");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Positions</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>

<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Positions";
        include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <div class="max-w-6xl mx-auto">
                <div class="flex items-center gap-3 mb-8 animate-fade-in-up">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500/20 to-fuchsia-500/20 flex items-center justify-center">
                        <i class="fas fa-briefcase text-violet-400"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-body">Positions</h1>
                        <p class="text-sm text-body-secondary">Manage employee positions</p>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="mb-6 rounded-2xl px-6 py-4 border <?php echo $message_type === 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?> animate-fade-in-up">
                        <div class="flex items-center gap-3">
                            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                            <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-1 animate-fade-in-up stagger-1">
                        <div class="glass-strong rounded-2xl p-6 card-hover">
                            <h2 class="text-lg font-bold text-white mb-4"><i class="fas fa-plus text-violet-400 mr-2"></i>Add Position</h2>
                            <form method="POST" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-zinc-400 mb-1">Position Name</label>
                                    <input type="text" name="position_name" required placeholder="e.g. Software Engineer" class="w-full px-4 py-2.5 rounded-xl border border-white/10 bg-white/[0.06] text-white placeholder-zinc-500 focus:ring-2 focus:ring-violet-500/30 focus:border-violet-400 transition-all duration-200">
                                </div>
                                <button type="submit" class="w-full bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-medium px-4 py-2.5 rounded-xl transition-all duration-200 hover:-translate-y-0.5 shadow-sm">
                                    <i class="fas fa-plus mr-2"></i>Add Position
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="lg:col-span-2 animate-fade-in-up stagger-2">
                        <div class="glass-strong rounded-2xl overflow-hidden card-hover">
                            <div class="px-6 py-4 border-b border-white/[0.06]">
                                <h2 class="text-lg font-bold text-white">All Positions</h2>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                        <tr>
                                            <th class="px-6 py-4">ID</th>
                                            <th class="px-6 py-4">Position</th>
                                            <th class="px-6 py-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/[0.06]">
                                        <?php if ($positions && $positions->num_rows > 0): ?>
                                            <?php while ($row = $positions->fetch_assoc()): ?>
                                                <tr class="hover:bg-white/[0.02] transition-colors">
                                                    <td class="px-6 py-4 text-zinc-500">#<?php echo $row['id']; ?></td>
                                                    <td class="px-6 py-4 font-medium text-white"><?php echo htmlspecialchars($row['position_name']); ?></td>
                                                    <td class="px-6 py-4 text-right">
                                                        <form method="POST" onsubmit="return confirm('Delete this position?')" class="inline">
                                                            <input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
                                                            <button type="submit" class="text-red-400 hover:text-red-300 transition-colors text-sm"><i class="fas fa-trash-alt"></i></button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="px-6 py-12 text-center text-zinc-500">
                                                    <i class="fas fa-briefcase text-3xl mb-2 block opacity-50"></i>
                                                    No positions found. Add your first position.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> AURA HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>

</html>