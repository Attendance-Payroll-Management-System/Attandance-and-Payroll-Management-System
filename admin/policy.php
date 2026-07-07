<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
require_once '../config/db.php';
require_once '../config/helpers.php';

$message = '';
$message_type = '';

// Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM policies WHERE id = $id");
    $message = 'Policy deleted successfully.';
    $message_type = 'success';
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $content = trim($_POST['content'] ?? '');
    $edit_id = (int)($_POST['edit_id'] ?? 0);

    if ($title && $content) {
        if ($edit_id > 0) {
            $stmt = $conn->prepare("UPDATE policies SET title = ?, category = ?, content = ? WHERE id = ?");
            $stmt->bind_param("sssi", $title, $category, $content, $edit_id);
            $message = 'Policy updated successfully.';
        } else {
            $stmt = $conn->prepare("INSERT INTO policies (title, category, content, created_by) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $title, $category, $content, $_SESSION['admin_id'] ?? null);
            $message = 'Policy added successfully.';
        }
        $stmt->execute();
        $stmt->close();
        $message_type = 'success';
        header('Location: policy.php?msg=' . urlencode($message));
        exit;
    } else {
        $message = 'Title and content are required.';
        $message_type = 'error';
    }
}

// Fetch policies
$policies = $conn->query("SELECT p.*, e.name as author_name FROM policies p LEFT JOIN employee e ON p.created_by = e.id ORDER BY p.category, p.title")->fetch_all(MYSQLI_ASSOC);

// Get categories
$categories = [];
foreach ($policies as $p) {
    if (!in_array($p['category'], $categories)) {
        $categories[] = $p['category'];
    }
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
        <?php $page_title = "Company Policies"; $page_subtitle = "Manage company policies and guidelines"; include "../includes/topbar.php"; ?>
        <main class="p-6 lg:p-8 space-y-6 flex-1 page-content w-full">

            <?php if ($message): ?>
            <div class="rounded-xl p-4 <?php echo $message_type === 'success' ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' : 'bg-rose-500/10 border border-rose-500/20 text-rose-400'; ?>">
                <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- Add/Edit Policy Form -->
            <div class="glass-strong rounded-2xl p-6" x-data="{ showForm: false, editMode: false }">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-white text-lg"><i class="fa-solid fa-plus-circle text-violet-400 mr-2"></i>Company Policies</h3>
                    <button @click="showForm = !showForm; editMode = false" class="bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500 text-white font-semibold text-sm px-5 py-2.5 rounded-xl transition-all duration-200 hover:scale-105 active:scale-95 flex items-center gap-2">
                        <i class="fa-solid" :class="showForm ? 'fa-times' : 'fa-plus'"></i>
                        <span x-text="showForm ? 'Cancel' : 'Add Policy'"></span>
                    </button>
                </div>

                <div x-show="showForm" x-transition class="mt-4">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="edit_id" :value="editMode ? document.getElementById('edit_id').value : ''">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Policy Title *</label>
                                <input type="text" name="title" required class="w-full bg-white/[0.06] border border-white/[0.08] rounded-xl px-4 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 focus:border-violet-500/50" placeholder="Enter policy title">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Category</label>
                                <select name="category" class="w-full bg-white/[0.06] border border-white/[0.08] rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-violet-500/50">
                                    <option value="General">General</option>
                                    <option value="Attendance">Attendance</option>
                                    <option value="Leave">Leave</option>
                                    <option value="Overtime">Overtime</option>
                                    <option value="Salary">Salary</option>
                                    <option value="Conduct">Conduct</option>
                                    <option value="Benefits">Benefits</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-400 mb-1.5">Policy Content *</label>
                            <textarea name="content" rows="6" required class="w-full bg-white/[0.06] border border-white/[0.08] rounded-xl px-4 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50 resize-y" placeholder="Enter policy content..."></textarea>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500 text-white font-semibold text-sm px-6 py-2.5 rounded-xl transition-all duration-200 hover:scale-105 active:scale-95">
                                <i class="fa-solid fa-save mr-2"></i>Save Policy
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Policies List -->
            <div class="space-y-4">
                <?php if (empty($policies)): ?>
                <div class="glass-strong rounded-2xl p-8 text-center">
                    <i class="fa-regular fa-file-lines text-4xl text-zinc-600 block mb-3"></i>
                    <p class="text-zinc-500">No company policies yet. Click "Add Policy" to create one.</p>
                </div>
                <?php else: ?>
                    <?php
                    $grouped = [];
                    foreach ($policies as $p) {
                        $grouped[$p['category']][] = $p;
                    }
                    foreach ($grouped as $cat => $items):
                    ?>
                    <div class="glass-strong rounded-2xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-white/[0.06] bg-gradient-to-r from-violet-500/10 to-fuchsia-500/10">
                            <h4 class="font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-folder text-violet-400"></i>
                                <?php echo htmlspecialchars($cat); ?>
                                <span class="badge badge-violet text-[10px]"><?php echo count($items); ?></span>
                            </h4>
                        </div>
                        <div class="divide-y divide-white/[0.04]">
                            <?php foreach ($items as $policy): ?>
                            <div class="px-6 py-4 hover:bg-white/[0.02] transition-colors" x-data="{ expanded: false }">
                                <div class="flex items-center justify-between cursor-pointer" @click="expanded = !expanded">
                                    <div class="flex items-center gap-3">
                                        <i class="fa-solid fa-file-alt text-violet-400"></i>
                                        <span class="font-medium text-white text-sm"><?php echo htmlspecialchars($policy['title']); ?></span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-[10px] text-zinc-500"><?php echo date('M d, Y', strtotime($policy['created_at'])); ?></span>
                                        <i class="fa-solid fa-chevron-down text-xs text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': expanded }"></i>
                                    </div>
                                </div>
                                <div x-show="expanded" x-transition class="mt-3 ml-8">
                                    <p class="text-sm text-zinc-300 whitespace-pre-wrap leading-relaxed"><?php echo nl2br(htmlspecialchars($policy['content'])); ?></p>
                                    <div class="mt-4 flex gap-2">
                                        <button @click="$dispatch('edit-policy', { id: <?php echo $policy['id']; ?>, title: '<?php echo addslashes($policy['title']); ?>', category: '<?php echo addslashes($policy['category']); ?>', content: `<?php echo addslashes($policy['content']); ?>` })" class="text-xs font-semibold text-violet-400 hover:text-violet-300 px-3 py-1.5 rounded-lg bg-violet-500/10 hover:bg-violet-500/20 transition-colors">
                                            <i class="fa-solid fa-pen mr-1"></i>Edit
                                        </button>
                                        <a href="policy.php?delete=<?php echo $policy['id']; ?>" onclick="return confirm('Are you sure you want to delete this policy?')" class="text-xs font-semibold text-rose-400 hover:text-rose-300 px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 transition-colors">
                                            <i class="fa-solid fa-trash mr-1"></i>Delete
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </main>
        <?php include "../includes/footer.php"; ?>
    </div>
</body>
</html>
