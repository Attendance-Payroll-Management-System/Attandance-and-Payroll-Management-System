<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';

$message = '';
$message_type = '';

// Add bonus
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_bonus'])) {
    $employee_id = (int)$_POST['employee_id'];
    $bonus_type_id = (int)$_POST['bonus_type_id'];
    $amount = (float)$_POST['amount'];
    $bonus_date = $_POST['bonus_date'];

    $stmt = $conn->prepare("INSERT INTO bonuses (employee_id, bonus_type_id, amount, bonus_date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('iids', $employee_id, $bonus_type_id, $amount, $bonus_date);
    if ($stmt->execute()) {
        $message = 'Bonus added successfully.';
        $message_type = 'success';
    } else {
        $message = 'Error adding bonus.';
        $message_type = 'error';
    }
    $stmt->close();
}

// Add bonus type
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_bonus_type'])) {
    $name = trim($_POST['new_bonus_type']);
    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO bonus_types (bonus_name) VALUES (?)");
        $stmt->bind_param('s', $name);
        if ($stmt->execute()) {
            $message = 'Bonus type added.';
            $message_type = 'success';
        } else {
            $message = 'Bonus type already exists.';
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Delete bonus type
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_type_id'])) {
    $id = (int)$_POST['delete_type_id'];
    $stmt = $conn->prepare("DELETE FROM bonus_types WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $message = 'Bonus type deleted.';
    $message_type = 'success';
}

// Delete bonus
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_bonus'])) {
    $id = (int)$_POST['id'];
    $stmt = $conn->prepare("DELETE FROM bonuses WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    header('Location: bonous.php');
    exit;
}

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');

$sql = "SELECT b.*, e.name, e.employee_code, bt.bonus_name
        FROM bonuses b
        JOIN employee e ON b.employee_id = e.id
        JOIN bonus_types bt ON b.bonus_type_id = bt.id
        WHERE b.bonus_date BETWEEN ? AND ?
        ORDER BY b.bonus_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$employees = $conn->query("SELECT id, name, employee_code FROM employee WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$bonus_types = $conn->query("SELECT * FROM bonus_types ORDER BY bonus_name")->fetch_all(MYSQLI_ASSOC);

$total_amount = array_sum(array_column($records, 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA HR · Bonuses</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php $page_title = "Bonuses"; include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">
            <header class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
                <div class="animate-fade-in-up">
                    <h1 class="text-2xl font-bold text-body tracking-tight">Bonuses</h1>
                    <p class="text-sm text-body-secondary mt-1">Manage employee bonuses and incentives.</p>
                </div>
                <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
                    <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                    <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>
                </form>
            </header>

            <?php if ($message): ?>
                <div class="mb-6 rounded-2xl px-6 py-4 shadow-sm border <?php echo $message_type == 'success' ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400' : 'bg-red-500/20 border-red-500/30 text-red-400'; ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?php echo $message_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-lg"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                <div class="lg:col-span-2">
                    <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                        <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                            <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-gift text-violet-400 mr-2"></i>Bonus Records</h2>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400">$<?php echo number_format($total_amount, 2); ?> total</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm whitespace-nowrap">
                                <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                    <tr>
                                        <th class="px-6 py-4">Employee</th>
                                        <th class="px-6 py-4">Type</th>
                                        <th class="px-6 py-4 text-right">Amount</th>
                                        <th class="px-6 py-4">Date</th>
                                        <th class="px-6 py-4 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/[0.06]">
                                    <?php if (empty($records)): ?>
                                    <tr><td colspan="5" class="px-6 py-12 text-center text-zinc-500">No bonus records found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($records as $r): ?>
                                        <tr class="hover:bg-white/[0.02] transition">
                                            <td class="px-6 py-4 font-medium text-white"><?php echo htmlspecialchars($r['name']); ?></td>
                                            <td class="px-6 py-4">
                                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-white/10 text-zinc-300"><?php echo htmlspecialchars($r['bonus_name']); ?></span>
                                            </td>
                                            <td class="px-6 py-4 text-right font-mono font-semibold text-emerald-400">+$<?php echo number_format($r['amount'], 2); ?></td>
                                            <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($r['bonus_date'])); ?></td>
                                            <td class="px-6 py-4 text-right">
                                                <form method="POST" onsubmit="return confirm('Delete this bonus?');" class="inline">
                                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" name="delete_bonus" class="text-red-400 hover:text-red-300 text-xs font-medium"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="font-bold text-white text-lg mb-6"><i class="fa-solid fa-plus text-violet-400 mr-2"></i>Add Bonus</h2>
                        <form method="POST" class="space-y-4 text-sm">
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Employee</label>
                                <select name="employee_id" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name'] . ' (' . $emp['employee_code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Bonus Type</label>
                                <select name="bonus_type_id" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($bonus_types as $bt): ?>
                                    <option value="<?php echo $bt['id']; ?>"><?php echo htmlspecialchars($bt['bonus_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Amount ($)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Date</label>
                                <input type="date" name="bonus_date" value="<?php echo date('Y-m-d'); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <button type="submit" name="add_bonus" class="w-full rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-gift"></i> Add Bonus
                            </button>
                        </form>
                    </section>

                    <section class="glass-strong rounded-2xl p-6" x-data="{ typeOpen: false }">
                        <button @click="typeOpen = !typeOpen" class="w-full flex items-center justify-between">
                            <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-tags text-violet-400 mr-2"></i>Bonus Types</h2>
                            <i class="fa-solid fa-chevron-down text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': typeOpen }"></i>
                        </button>
                        <div x-show="typeOpen" x-transition:enter="transition-all duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="mt-4 space-y-4">
                            <?php
                            $bt_result = $conn->query("SELECT bt.*, (SELECT COUNT(*) FROM bonuses b WHERE b.bonus_type_id = bt.id) as used_count FROM bonus_types bt ORDER BY bt.bonus_name");
                            $all_types = $bt_result->fetch_all(MYSQLI_ASSOC);
                            ?>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($all_types as $bt): ?>
                                <div class="flex items-center gap-2 bg-white/[0.06] rounded-full px-3 py-1.5 text-sm">
                                    <span class="text-zinc-300"><?php echo htmlspecialchars($bt['bonus_name']); ?></span>
                                    <span class="text-[10px] text-zinc-500">(<?php echo $bt['used_count']; ?>)</span>
                                    <?php if ($bt['used_count'] == 0): ?>
                                    <form method="POST" onsubmit="return confirm('Delete bonus type &quot;<?php echo htmlspecialchars($bt['bonus_name']); ?>&quot;?')" class="inline">
                                        <input type="hidden" name="delete_type_id" value="<?php echo $bt['id']; ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs ml-1"><i class="fa-solid fa-times"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <form method="POST" class="flex gap-2">
                                <input type="text" name="new_bonus_type" required placeholder="New bonus type name" class="flex-1 rounded-xl border border-white/10 bg-white/[0.06] px-4 py-2 text-sm text-white placeholder-zinc-500 outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                <button type="submit" name="add_bonus_type" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-4 py-2 shadow-sm transition whitespace-nowrap"><i class="fa-solid fa-plus"></i> Add</button>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> ENTERPRISE HR PLATFORMS</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>
