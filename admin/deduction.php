<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$message = '';
$message_type = '';

// Add deduction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_deduction'])) {
    $employee_id = (int)$_POST['employee_id'];
    $deduction_type_id = (int)$_POST['deduction_type_id'];
    $amount = (float)$_POST['amount'];
    $deduction_date = $_POST['deduction_date'];

    $stmt = $conn->prepare("INSERT INTO deductions (employee_id, deduction_type_id, amount, deduction_date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('iids', $employee_id, $deduction_type_id, $amount, $deduction_date);
    if ($stmt->execute()) {
        $message = 'Deduction added successfully.';
        $message_type = 'success';
    } else {
        $message = 'Error adding deduction.';
        $message_type = 'error';
    }
    $stmt->close();
}

// Add deduction type
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_deduction_type'])) {
    $name = trim($_POST['new_deduction_type']);
    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO deduction_types (deduction_name) VALUES (?)");
        $stmt->bind_param('s', $name);
        if ($stmt->execute()) {
            $message = 'Deduction type added.';
            $message_type = 'success';
        } else {
            $message = 'Deduction type already exists.';
            $message_type = 'error';
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_deduction_type'])) {
    $id = (int)$_POST['delete_deduction_type'];
    $stmt = $conn->prepare("DELETE FROM deduction_types WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $message = 'Deduction type deleted.';
    $message_type = 'success';
}

// Delete deduction
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_deduction'])) {
    $id = (int)$_POST['id'];
    $stmt = $conn->prepare("DELETE FROM deductions WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    header('Location: deduction.php');
    exit;
}

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-t');

$sql = "SELECT d.*, e.name, e.employee_code, dt.deduction_name
        FROM deductions d
        JOIN employee e ON d.employee_id = e.id
        JOIN deduction_types dt ON d.deduction_type_id = dt.id
        WHERE d.deduction_date BETWEEN ? AND ?
        ORDER BY d.deduction_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $from_date, $to_date);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$employees = $conn->query("SELECT id, name, employee_code FROM employee WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$deduction_types = $conn->query("SELECT * FROM deduction_types ORDER BY deduction_name")->fetch_all(MYSQLI_ASSOC);

$total_amount = array_sum(array_column($records, 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HNIN AKARI NWE · Deductions</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{ sidebarOpen: false }" class="bg-slate-50 dark:bg-[#09090b] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Deductions";
            $page_subtitle = "Manage employee deductions and withholdings.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
            <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
            <button type="submit" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
        </form>
        <?php $page_actions = ob_get_clean(); include "../includes/topbar.php"; ?>
        <main class="flex-1 p-8 overflow-y-auto">

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
                    <?php if (empty($records)): ?>
                    <section class="glass-strong rounded-2xl p-12 text-center">
                        <svg class="w-24 h-24 mx-auto mb-6 text-zinc-600 dark:text-zinc-700" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="50" cy="35" r="18" stroke="currentColor" stroke-width="2.5" opacity="0.3"/>
                            <path d="M22 85c0-15.46 12.54-28 28-28s28 12.54 28 28" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity="0.3"/>
                            <line x1="30" y1="50" x2="70" y2="50" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity="0.15"/>
                            <line x1="35" y1="55" x2="65" y2="55" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.1"/>
                            <path d="M78 22l6-6 12 12" stroke="url(#grad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.6"/>
                            <circle cx="86" cy="16" r="14" stroke="currentColor" stroke-width="2" opacity="0.15"/>
                            <defs><linearGradient id="grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#e879f9"/></linearGradient></defs>
                        </svg>
                        <h3 class="text-xl font-bold text-white">No deduction records</h3>
                        <p class="text-zinc-400 mt-2 max-w-md mx-auto">Deductions applied to employee salaries will appear here. Use the form to add a new deduction.</p>
                    </section>
                    <?php else: ?>
                    <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                        <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                            <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-chart-line text-violet-400 mr-2"></i>Deduction Records</h2>
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
                                        <?php foreach ($records as $r): ?>
                                        <tr class="hover:bg-white/[0.02] transition">
                                            <td class="px-6 py-4 font-medium text-white"><?php echo htmlspecialchars($r['name']); ?></td>
                                            <td class="px-6 py-4">
                                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-white/10 text-zinc-300"><?php echo htmlspecialchars($r['deduction_name']); ?></span>
                                            </td>
                                            <td class="px-6 py-4 text-right font-mono font-semibold text-rose-400">-$<?php echo number_format($r['amount'], 2); ?></td>
                                            <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($r['deduction_date'])); ?></td>
                                            <td class="px-6 py-4 text-right">
                                                <form method="POST" onsubmit="return confirm('Delete this deduction?');" class="inline">
                                                <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" name="delete_deduction" class="text-red-400 hover:text-red-300 text-xs font-medium"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <?php endif; ?>
                </div>

                <div class="space-y-6">
                    <section class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="font-bold text-white text-lg mb-6"><i class="fa-solid fa-plus text-violet-400 mr-2"></i>Add Deduction</h2>
                        <form method="POST" class="space-y-4 text-sm">
                        <?php echo csrf_field(); ?>
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
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Deduction Type</label>
                                <select name="deduction_type_id" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($deduction_types as $dt): ?>
                                    <option value="<?php echo $dt['id']; ?>"><?php echo htmlspecialchars($dt['deduction_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Amount ($)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Date</label>
                                <input type="date" name="deduction_date" value="<?php echo date('Y-m-d'); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                            </div>
                            <button type="submit" name="add_deduction" class="w-full rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-chart-line"></i> Add Deduction
                            </button>
                        </form>
                    </section>

                    <section class="glass-strong rounded-2xl p-6" x-data="{ typeOpen: false }">
                        <button @click="typeOpen = !typeOpen" class="w-full flex items-center justify-between">
                            <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-tags text-violet-400 mr-2"></i>Deduction Types</h2>
                            <i class="fa-solid fa-chevron-down text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': typeOpen }"></i>
                        </button>
                        <div x-show="typeOpen" x-transition:enter="transition-all duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="mt-4 space-y-4">
                            <?php
                            $dt_result = $conn->query("SELECT dt.*, (SELECT COUNT(*) FROM deductions d WHERE d.deduction_type_id = dt.id) as used_count FROM deduction_types dt ORDER BY dt.deduction_name");
                            $all_dt = $dt_result->fetch_all(MYSQLI_ASSOC);
                            ?>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($all_dt as $dt): ?>
                                <div class="flex items-center gap-2 bg-white/[0.06] rounded-full px-3 py-1.5 text-sm">
                                    <span class="text-zinc-300"><?php echo htmlspecialchars($dt['deduction_name']); ?></span>
                                    <span class="text-[10px] text-zinc-500">(<?php echo $dt['used_count']; ?>)</span>
                                    <?php if ($dt['used_count'] == 0): ?>
                                    <form method="POST" onsubmit="return confirm('Delete deduction type?')" class="inline">
                                    <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_deduction_type" value="<?php echo $dt['id']; ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs ml-1"><i class="fa-solid fa-times"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <form method="POST" class="flex gap-2">
                            <?php echo csrf_field(); ?>
                                <input type="text" name="new_deduction_type" required placeholder="New deduction type name" class="flex-1 rounded-xl border border-white/10 bg-white/[0.06] px-4 py-2 text-sm text-white placeholder-zinc-500 outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-500/30">
                                <button type="submit" name="add_deduction_type" class="rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white font-semibold text-sm px-4 py-2 shadow-sm transition whitespace-nowrap"><i class="fa-solid fa-plus"></i> Add</button>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
        </main>

        <footer class="glass-strong border-t border-white/[0.06] px-8 py-3 text-xs text-zinc-500 flex justify-between items-center mt-auto">
            <span>&copy; <?php echo date('Y'); ?> HNIN AKARI NWE</span>
            <span class="flex items-center space-x-1.5 font-medium text-emerald-400">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                <span>System Secure</span>
            </span>
        </footer>
    </div>
</body>
</html>
