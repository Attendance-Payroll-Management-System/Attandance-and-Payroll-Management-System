<?php
session_start();
require_once '../config/auth.php';
require_admin_login();
require_once '../config/db.php';
require_once '../config/helpers.php';

$message = '';
$message_type = '';
$currency = get_currency($conn);

// Add bonus (individual or bulk)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_bonus'])) {
    $apply_to = $_POST['apply_to'] ?? 'selected';
    $title = trim($_POST['title']);
    $amount = (float)$_POST['amount'];
    $description = trim($_POST['description'] ?? '');
    $bonus_date = $_POST['bonus_date'];
    $bonus_type_id = !empty($_POST['bonus_type_id']) ? (int)$_POST['bonus_type_id'] : null;

    if (empty($title) || $amount <= 0) {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("INSERT INTO bonuses (employee_id, title, bonus_type_id, amount, description, bonus_date) VALUES (?, ?, ?, ?, ?, ?)");

        if ($apply_to === 'all') {
            $employees = $conn->query("SELECT id FROM employee WHERE status = 'active'")->fetch_all(MYSQLI_ASSOC);
            $count = 0;
            foreach ($employees as $emp) {
                $eid = (int)$emp['id'];
                $stmt->bind_param('isidds', $eid, $title, $bonus_type_id, $amount, $description, $bonus_date);
                if ($stmt->execute()) $count++;
            }
            $message = "Bonus applied to $count employees successfully.";
            $message_type = 'success';
        } else {
            $employee_id = (int)$_POST['employee_id'];
            $stmt->bind_param('isidds', $employee_id, $title, $bonus_type_id, $amount, $description, $bonus_date);
            if ($stmt->execute()) {
                $message = 'Bonus added successfully.';
                $message_type = 'success';
            } else {
                $message = 'Error adding bonus.';
                $message_type = 'error';
            }
        }
        $stmt->close();
    }
}

// Edit bonus
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_bonus'])) {
    $id = (int)$_POST['id'];
    $title = trim($_POST['title']);
    $amount = (float)$_POST['amount'];
    $description = trim($_POST['description'] ?? '');
    $bonus_date = $_POST['bonus_date'];

    $stmt = $conn->prepare("UPDATE bonuses SET title = ?, amount = ?, description = ?, bonus_date = ? WHERE id = ?");
    $stmt->bind_param('sdssi', $title, $amount, $description, $bonus_date, $id);
    if ($stmt->execute()) {
        $message = 'Bonus updated successfully.';
        $message_type = 'success';
    } else {
        $message = 'Error updating bonus.';
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
$search = $_GET['search'] ?? '';

$sql = "SELECT b.*, e.name, e.employee_code, bt.bonus_name
        FROM bonuses b
        JOIN employee e ON b.employee_id = e.id
        LEFT JOIN bonus_types bt ON b.bonus_type_id = bt.id
        WHERE b.bonus_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];
$types = 'ss';

if (!empty($search)) {
    $sql .= " AND (e.name LIKE ? OR b.title LIKE ? OR e.employee_code LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'sss';
}

$sql .= " ORDER BY b.bonus_date DESC, b.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
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
    <title>HNIN AKARI NWE · Bonuses</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <?php include "../includes/header.php"; ?>
</head>
<body x-data="{}" class="bg-slate-50 dark:bg-[#0B1120] text-slate-900 dark:text-white font-sans antialiased min-h-screen flex">
    <?php include "../includes/sidebar.php"; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php
            $page_title = "Bonuses";
            $page_subtitle = "Manage employee bonuses and incentives.";
            ob_start();
        ?>
        <form method="GET" class="flex flex-wrap items-center gap-3 glass-strong rounded-xl p-3">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search employee or title..." class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5 w-56">
            <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
            <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="bg-white/[0.06] border-white/10 text-white placeholder-zinc-500 text-sm rounded-lg p-2.5">
            <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-2.5 shadow-sm transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
            <?php if (!empty($search)): ?>
            <a href="bonous.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="rounded-xl bg-white/10 hover:bg-white/15 text-zinc-300 font-semibold text-sm px-4 py-2.5 transition">Clear</a>
            <?php endif; ?>
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
                            <rect x="20" y="30" width="60" height="50" rx="8" stroke="currentColor" stroke-width="2" opacity="0.2"/>
                            <path d="M20 50h60" stroke="currentColor" stroke-width="1.5" opacity="0.15"/>
                            <polygon points="50,15 60,35 40,35" stroke="currentColor" stroke-width="2" opacity="0.2"/>
                            <polygon points="50,15 60,35 40,35" fill="currentColor" opacity="0.06"/>
                            <circle cx="42" cy="43" r="3" fill="currentColor" opacity="0.15"/>
                            <circle cx="58" cy="43" r="3" fill="currentColor" opacity="0.15"/>
                            <path d="M75 20l6-6 12 12" stroke="url(#grad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.6"/>
                            <circle cx="82" cy="14" r="14" stroke="currentColor" stroke-width="2" opacity="0.15"/>
                            <defs><linearGradient id="grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#e879f9"/></linearGradient></defs>
                        </svg>
                        <h3 class="text-xl font-bold text-white">No bonus records</h3>
                        <p class="text-zinc-400 mt-2 max-w-md mx-auto">Bonuses awarded to employees will appear here. Use the form to add a new bonus.</p>
                    </section>
                    <?php else: ?>
                    <section class="card-hover glass-strong rounded-2xl overflow-hidden">
                        <div class="p-6 border-b border-white/[0.06] flex items-center justify-between">
                            <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-gift text-blue-400 mr-2"></i>Bonus Records</h2>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-indigo-500/20 text-indigo-400"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?> total</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm whitespace-nowrap">
                                <thead class="text-zinc-500 text-xs font-bold uppercase tracking-wider border-b border-white/[0.06]">
                                    <tr>
                                        <th class="px-6 py-4">Employee</th>
                                        <th class="px-6 py-4">Title</th>
                                        <th class="px-6 py-4 text-right">Amount</th>
                                        <th class="px-6 py-4">Date</th>
                                        <th class="px-6 py-4 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/[0.06]">
                                        <?php foreach ($records as $r): ?>
                                        <tr class="hover:bg-white/[0.02] transition">
                                            <td class="px-6 py-4 font-medium text-white">
                                                <div><?php echo htmlspecialchars($r['name']); ?></div>
                                                <div class="text-xs text-zinc-500"><?php echo htmlspecialchars($r['employee_code']); ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-zinc-300"><?php echo htmlspecialchars($r['title'] ?? $r['bonus_name'] ?? '-'); ?></div>
                                                <?php if (!empty($r['description'])): ?>
                                                <div class="text-xs text-zinc-500 max-w-[200px] truncate" title="<?php echo htmlspecialchars($r['description']); ?>"><?php echo htmlspecialchars($r['description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-right font-mono font-semibold text-emerald-400">+<?php echo $currency; ?> <?php echo number_format($r['amount'], 2); ?></td>
                                            <td class="px-6 py-4"><?php echo date('M d, Y', strtotime($r['bonus_date'])); ?></td>
                                            <td class="px-6 py-4 text-right space-x-3">
                                                <button type="button" @click="$dispatch('open-edit', { id: <?php echo $r['id']; ?>, title: '<?php echo htmlspecialchars(addslashes($r['title'] ?? ''), ENT_QUOTES); ?>', amount: <?php echo $r['amount']; ?>, description: '<?php echo htmlspecialchars(addslashes($r['description'] ?? ''), ENT_QUOTES); ?>', date: '<?php echo $r['bonus_date']; ?>' })" class="text-blue-400 hover:text-blue-300 text-xs font-medium"><i class="fa-solid fa-pen"></i></button>
                                                <form method="POST" onsubmit="return confirm('Delete this bonus?');" class="inline">
                                                <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" name="delete_bonus" class="text-red-400 hover:text-red-300 text-xs font-medium"><i class="fa-solid fa-trash"></i></button>
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

                <div class="space-y-6" x-data="{ applyTo: 'selected', typeOpen: false }">
                    <section class="group glass-strong rounded-2xl hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 p-6">
                        <h2 class="font-bold text-white text-lg mb-6"><i class="fa-solid fa-plus text-blue-400 mr-2"></i>Add Bonus</h2>
                        <form method="POST" class="space-y-4 text-sm">
                        <?php echo csrf_field(); ?>
                            <input type="hidden" name="apply_to" :value="applyTo">
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-2">Apply To</label>
                                <div class="flex gap-4">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" x-model="applyTo" value="selected" class="accent-blue-500">
                                        <span class="text-sm text-zinc-300">Selected Employee</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" x-model="applyTo" value="all" class="accent-blue-500">
                                        <span class="text-sm text-zinc-300">All Employees</span>
                                    </label>
                                </div>
                            </div>
                            <div x-show="applyTo === 'selected'" x-transition>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Employee</label>
                                <select name="employee_id" :required="applyTo === 'selected'" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name'] . ' (' . $emp['employee_code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Title</label>
                                <select name="title" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                    <option value="">-- Select Title --</option>
                                    <?php foreach ($bonus_types as $bt): ?>
                                    <option value="<?php echo htmlspecialchars($bt['bonus_name']); ?>"><?php echo htmlspecialchars($bt['bonus_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Amount ($)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Description / Reason</label>
                                <textarea name="description" rows="2" placeholder="Optional description or reason..." class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30 resize-none"></textarea>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Effective Date</label>
                                <input type="date" name="bonus_date" value="<?php echo date('Y-m-d'); ?>" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                            </div>
                            <button type="submit" name="add_bonus" class="w-full rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-gift"></i> <span x-text="applyTo === 'all' ? 'Apply to All Employees' : 'Add Bonus'">Add Bonus</span>
                            </button>
                        </form>
                    </section>

                    <section class="glass-strong rounded-2xl p-6" x-data="{ typeOpen: false }">
                        <button @click="typeOpen = !typeOpen" class="w-full flex items-center justify-between">
                            <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-tags text-blue-400 mr-2"></i>Bonus Types</h2>
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
                                    <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_type_id" value="<?php echo $bt['id']; ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs ml-1"><i class="fa-solid fa-times"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <form method="POST" class="flex gap-2">
                            <?php echo csrf_field(); ?>
                                <input type="text" name="new_bonus_type" required placeholder="New bonus type name" class="flex-1 rounded-xl border border-white/10 bg-white/[0.06] px-4 py-2 text-sm text-white placeholder-zinc-500 outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                <button type="submit" name="add_bonus_type" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-4 py-2 shadow-sm transition whitespace-nowrap"><i class="fa-solid fa-plus"></i> Add</button>
                            </form>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Edit Bonus Modal -->
            <div x-data="{ open: false, editId: 0, editTitle: '', editAmount: 0, editDesc: '', editDate: '' }"
                 @open-edit.window="open = true; editId = $event.detail.id; editTitle = $event.detail.title; editAmount = $event.detail.amount; editDesc = $event.detail.description; editDate = $event.detail.date"
                 x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="open = false"></div>
                <div class="relative glass-strong rounded-2xl p-8 w-full max-w-md shadow-2xl" @click.stop x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="font-bold text-white text-lg"><i class="fa-solid fa-pen text-blue-400 mr-2"></i>Edit Bonus</h2>
                        <button @click="open = false" class="text-zinc-400 hover:text-white transition"><i class="fa-solid fa-xmark text-lg"></i></button>
                    </div>
                    <form method="POST" class="space-y-4 text-sm">
                    <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" :value="editId">
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Title</label>
                            <select name="title" x-model="editTitle" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                                <option value="">-- Select Title --</option>
                                <?php foreach ($bonus_types as $bt): ?>
                                <option value="<?php echo htmlspecialchars($bt['bonus_name']); ?>"><?php echo htmlspecialchars($bt['bonus_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Amount ($)</label>
                            <input type="number" step="0.01" min="0.01" name="amount" x-model="editAmount" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Description / Reason</label>
                            <textarea name="description" x-model="editDesc" rows="2" class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30 resize-none"></textarea>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-zinc-400 block mb-1.5">Effective Date</label>
                            <input type="date" name="bonus_date" x-model="editDate" required class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white placeholder-zinc-500 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30">
                        </div>
                        <div class="flex gap-3 pt-2">
                            <button type="button" @click="open = false" class="flex-1 rounded-xl bg-white/10 hover:bg-white/15 text-zinc-300 font-semibold text-sm px-5 py-3 transition">Cancel</button>
                            <button type="submit" name="edit_bonus" class="flex-1 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm px-5 py-3 shadow-sm transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-check"></i> Save Changes
                            </button>
                        </div>
                    </form>
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
